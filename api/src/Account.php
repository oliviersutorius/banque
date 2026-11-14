<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;
use RuntimeException;

/**
 * Le compte unique de l'application, stocké dans data/compte.json :
 *
 *   identifiant       nom de connexion
 *   mot_de_passe      empreinte Argon2id (password_hash), jamais le mot de passe
 *   totp              secret TOTP chiffré par SecretBox (base64), ou null si la double authentification n'est pas
 *                     configurée (poste local)
 *   codes_secours     empreintes SHA-256 des codes de secours restants (chacun ne sert qu'une fois)
 *   dernier_pas_totp  dernier pas TOTP accepté : un même code ne peut pas servir deux fois
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
        private readonly string $path,
        private readonly JsonFile $json,
        private readonly ?SecretBox $box,
        private readonly array $hashOptions = [],
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->path);
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
        $factor = $withTotp ? $this->newSecondFactor() : ['data' => ['totp' => null, 'codes_secours' => []], 'secret' => null, 'codes' => []];

        $this->json->write($this->path, [
            'identifiant' => $username,
            'mot_de_passe' => password_hash($password, PASSWORD_ARGON2ID, $this->hashOptions),
            'dernier_pas_totp' => 0,
        ] + $factor['data']);

        return ['secret' => $factor['secret'], 'codes' => $factor['codes']];
    }

    public function changePassword(#[\SensitiveParameter] string $password): void
    {
        self::validatePassword($password);
        $hash = password_hash($password, PASSWORD_ARGON2ID, $this->hashOptions);
        $this->json->update($this->path, fn (?array $data): array => ['mot_de_passe' => $hash] + $this->requireData($data));
    }

    /**
     * Remplace le secret TOTP et les codes de secours (téléphone perdu, ajout du TOTP à un compte local).
     *
     * @return array{secret: string, codes: list<string>}
     */
    public function resetSecondFactor(): array
    {
        $factor = $this->newSecondFactor();
        $this->json->update($this->path, fn (?array $data): array => $factor['data'] + ['dernier_pas_totp' => 0] + $this->requireData($data));

        return ['secret' => (string) $factor['secret'], 'codes' => $factor['codes']];
    }

    public function username(): ?string
    {
        return $this->exists() ? (string) ($this->json->read($this->path)['identifiant'] ?? '') : null;
    }

    /**
     * Identifiant et mot de passe. Même durée et même résultat (false) pour un identifiant inconnu ou un mot de
     * passe faux. Une empreinte créée avec d'anciens paramètres est recalculée à la volée.
     */
    public function verifyPassword(string $username, #[\SensitiveParameter] string $password): bool
    {
        $data = $this->exists() ? $this->json->read($this->path) : [];
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
        return $this->exists() && is_string($this->json->read($this->path)['totp'] ?? null);
    }

    public function recoveryCodesLeft(): int
    {
        $codes = $this->exists() ? ($this->json->read($this->path)['codes_secours'] ?? []) : [];

        return is_array($codes) ? count($codes) : 0;
    }

    /**
     * Code TOTP à 6 chiffres, ou code de secours (consommé s'il est bon). Lecture et écriture sous verrou : un même
     * code ne peut pas être accepté deux fois, même par deux requêtes simultanées.
     *
     * @return 'totp'|'secours'|null le facteur accepté, null si le code est faux
     */
    public function verifySecondFactor(#[\SensitiveParameter] string $code, int $now): ?string
    {
        $accepted = null;
        $this->json->update($this->path, function (?array $data) use ($code, $now, &$accepted): ?array {
            $data = $this->requireData($data);
            if (!is_string($data['totp'] ?? null)) {
                return null;
            }
            $step = Totp::verify($this->requireBox()->decrypt($data['totp']), $code, $now, (int) ($data['dernier_pas_totp'] ?? 0));
            if ($step !== null) {
                $accepted = 'totp';
                return ['dernier_pas_totp' => $step] + $data;
            }

            $codeHash = self::hashRecoveryCode($code);
            $remaining = [];
            foreach (is_array($data['codes_secours'] ?? null) ? $data['codes_secours'] : [] as $stored) {
                if (is_string($stored) && hash_equals($stored, $codeHash) && $accepted === null) {
                    $accepted = 'secours';
                    continue;
                }
                $remaining[] = $stored;
            }

            return $accepted === null ? null : ['codes_secours' => $remaining] + $data;
        });

        return $accepted;
    }

    /**
     * @return array{data: array{totp: string, codes_secours: list<string>}, secret: string, codes: list<string>}
     */
    private function newSecondFactor(): array
    {
        $secret = Totp::generateSecret();
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = self::randomRecoveryCode();
        }

        return [
            'data' => [
                'totp' => $this->requireBox()->encrypt($secret),
                'codes_secours' => array_map(self::hashRecoveryCode(...), $codes),
            ],
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
     * @param array<mixed>|null $data
     * @return array<mixed>
     */
    private function requireData(?array $data): array
    {
        if ($data === null) {
            throw new RuntimeException('Aucun compte : lancer php api/bin/compte.php init');
        }

        return $data;
    }
}
