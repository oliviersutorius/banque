<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;
use RuntimeException;

/**
 * Le compte unique de l'application : tables « compte » (ligne id = 1) et « codes_secours ».
 *
 *   identifiant       nom de connexion
 *   mot_de_passe      empreinte Argon2id (password_hash), jamais le mot de passe
 *   totp              secret TOTP chiffré par SecretBox (base64), ou NULL si la double authentification n'est pas
 *                     configurée (poste local)
 *   dernier_pas_totp  dernier pas TOTP accepté : un même code ne peut pas servir deux fois
 *   codes_secours     empreintes SHA-256 des codes de secours restants (chacun ne sert qu'une fois)
 */
final class Account
{
    public const MIN_PASSWORD_LENGTH = 12;
    public const RECOVERY_CODES = 10;
    /**
     * Empreinte d'un mot de passe aléatoire inconnu, vérifiée quand l'identifiant est faux : la réponse prend le
     * même temps que le compte existe ou non. Ce n'est pas un secret.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$MW00OElRVXpBMGVvV1J0OA$a/QFvRuJdKCPENoGSD6jsOUcF8WEPnft6yOWGWyWdgI';
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * @param array<string, int> $hashOptions options de password_hash (les tests réduisent le coût)
     */
    public function __construct(
        private readonly Database $db,
        private readonly ?SecretBox $box,
        private readonly array $hashOptions = [],
    ) {
    }

    public function exists(): bool
    {
        return $this->row() !== null;
    }

    /**
     * @throws InvalidArgumentException si le mot de passe est trop court
     */
    public static function validatePassword(#[\SensitiveParameter] string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Mot de passe trop court : ' . self::MIN_PASSWORD_LENGTH . ' caractères minimum');
        }
    }

    /**
     * Crée (ou remplace) le compte.
     *
     * @param bool $withTotp false pour un compte du poste local, sans double authentification
     * @return array{secret: string|null, codes: list<string>} secret TOTP en binaire et codes de secours en clair,
     *         à montrer une seule fois à l'utilisateur
     */
    public function create(string $username, #[\SensitiveParameter] string $password, bool $withTotp): array
    {
        $username = trim($username);
        if ($username === '' || mb_strlen($username) > 100) {
            throw new InvalidArgumentException('Identifiant vide ou trop long');
        }
        self::validatePassword($password);
        $factor = $withTotp ? $this->newSecondFactor() : ['totp' => null, 'hashes' => [], 'secret' => null, 'codes' => []];
        $hash = password_hash($password, PASSWORD_ARGON2ID, $this->hashOptions);

        $this->db->transaction(function () use ($username, $hash, $factor): void {
            $this->db->run('DELETE FROM compte');
            $this->db->run('DELETE FROM sessions');
            $this->db->run(
                'INSERT INTO compte (id, identifiant, mot_de_passe, totp, dernier_pas_totp) VALUES (1, ?, ?, ?, 0)',
                [$username, $hash, $factor['totp']],
            );
            $this->saveRecoveryHashes($factor['hashes']);
        });

        return ['secret' => $factor['secret'], 'codes' => $factor['codes']];
    }

    public function changePassword(#[\SensitiveParameter] string $password): void
    {
        self::validatePassword($password);
        $this->requireRow();
        $this->db->run('UPDATE compte SET mot_de_passe = ? WHERE id = 1', [password_hash($password, PASSWORD_ARGON2ID, $this->hashOptions)]);
    }

    /**
     * Ferme toutes les sessions ouvertes (après un changement de mot de passe ou de second facteur).
     */
    public function closeSessions(): void
    {
        $this->db->run('DELETE FROM sessions');
    }

    /**
     * Remplace le secret TOTP et les codes de secours (téléphone perdu, ajout du TOTP à un compte local).
     *
     * @return array{secret: string, codes: list<string>}
     */
    public function resetSecondFactor(): array
    {
        $this->requireRow();
        $factor = $this->newSecondFactor();
        $this->db->transaction(function () use ($factor): void {
            $this->db->run('UPDATE compte SET totp = ?, dernier_pas_totp = 0 WHERE id = 1', [$factor['totp']]);
            $this->db->run('DELETE FROM codes_secours');
            $this->saveRecoveryHashes($factor['hashes']);
        });

        return ['secret' => (string) $factor['secret'], 'codes' => $factor['codes']];
    }

    public function username(): ?string
    {
        $row = $this->row();

        return $row === null ? null : (string) $row['identifiant'];
    }

    /**
     * Identifiant et mot de passe. Même durée et même résultat (false) pour un identifiant inconnu ou un mot de
     * passe faux. Une empreinte créée avec d'anciens paramètres est recalculée à la volée.
     */
    public function verifyPassword(string $username, #[\SensitiveParameter] string $password): bool
    {
        $data = $this->row() ?? [];
        $hash = is_string($data['mot_de_passe'] ?? null) ? $data['mot_de_passe'] : self::DUMMY_HASH;
        $passwordOk = password_verify($password, $hash);
        $usernameOk = is_string($data['identifiant'] ?? null) && hash_equals($data['identifiant'], trim($username));
        if (!$passwordOk || !$usernameOk || $hash === self::DUMMY_HASH) {
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->hashOptions)) {
            $this->changePassword($password);
        }

        return true;
    }

    public function hasTotp(): bool
    {
        return is_string($this->row()['totp'] ?? null);
    }

    public function recoveryCodesLeft(): int
    {
        return (int) $this->db->value('SELECT count(*) FROM codes_secours');
    }

    /**
     * Code TOTP à 6 chiffres, ou code de secours (consommé s'il est bon). Lecture et écriture sous verrou : un même
     * code ne peut pas être accepté deux fois, même par deux requêtes simultanées.
     *
     * @return 'totp'|'secours'|null le facteur accepté, null si le code est faux
     */
    public function verifySecondFactor(#[\SensitiveParameter] string $code, int $now): ?string
    {
        return $this->db->transaction(function () use ($code, $now): ?string {
            $data = $this->requireRow();
            if (!is_string($data['totp'] ?? null)) {
                return null;
            }
            $step = Totp::verify($this->requireBox()->decrypt($data['totp']), $code, $now, (int) $data['dernier_pas_totp']);
            if ($step !== null) {
                $this->db->run('UPDATE compte SET dernier_pas_totp = ? WHERE id = 1', [$step]);
                return 'totp';
            }
            $used = $this->db->run('DELETE FROM codes_secours WHERE empreinte = ?', [self::hashRecoveryCode($code)])->rowCount();

            return $used > 0 ? 'secours' : null;
        });
    }

    /**
     * @return array{totp: string, hashes: list<string>, secret: string, codes: list<string>}
     */
    private function newSecondFactor(): array
    {
        $secret = Totp::generateSecret();
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = self::randomRecoveryCode();
        }

        return [
            'totp' => $this->requireBox()->encrypt($secret),
            'hashes' => array_map(self::hashRecoveryCode(...), $codes),
            'secret' => $secret,
            'codes' => $codes,
        ];
    }

    /**
     * Format « abcde-fghij » : 10 caractères tirés parmi 31 (environ 50 bits), sans caractères ambigus (0/o, 1/l/i).
     */
    private static function randomRecoveryCode(): string
    {
        $code = '';
        for ($i = 0; $i < 10; $i++) {
            $code .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
        }

        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    /**
     * Codes aléatoires de 50 bits : un SHA-256 suffit (pas de dictionnaire possible), et la vérification des
     * 10 codes reste rapide. La saisie est tolérante : majuscules, espaces et tiret facultatifs.
     */
    private static function hashRecoveryCode(#[\SensitiveParameter] string $code): string
    {
        return hash('sha256', strtolower((string) preg_replace('/[\s-]+/', '', $code)));
    }

    private function requireBox(): SecretBox
    {
        if ($this->box === null) {
            throw new RuntimeException('Clé de chiffrement absente : définir BANQUE_CLE ou BANQUE_CLE_FICHIER (php api/bin/cle.php)');
        }

        return $this->box;
    }

    /**
     * @param list<string> $hashes
     */
    private function saveRecoveryHashes(array $hashes): void
    {
        foreach ($hashes as $hash) {
            $this->db->run('INSERT INTO codes_secours (compte_id, empreinte) VALUES (1, ?)', [$hash]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(): ?array
    {
        return $this->db->one('SELECT identifiant, mot_de_passe, totp, dernier_pas_totp FROM compte WHERE id = 1');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRow(): array
    {
        return $this->row() ?? throw new RuntimeException('Aucun compte : lancer php api/bin/compte.php init');
    }
}
