<?php

declare(strict_types=1);

namespace Banque;

/**
 * Limitation des tentatives de connexion (table « tentatives »).
 *
 * Deux compteurs : un par adresse IP (« ip:<adresse> ») et un global pour le compte (« compte »), qui freine aussi
 * une attaque répartie sur beaucoup d'adresses. Tous les $maxFailures échecs, le compteur est verrouillé, d'abord
 * $lockSeconds (15 min), puis le double à chaque nouveau verrouillage, au plus 24 h. Une connexion réussie remet
 * à zéro le compteur de son IP et celui du compte ; un compteur sans échec depuis 24 h est oublié.
 */
final class LoginThrottle
{
    public const ACCOUNT_KEY = 'compte';
    private const MAX_LOCK_SECONDS = 86400;
    private const FORGET_AFTER = 86400;

    public function __construct(
        private readonly Database $db,
        private readonly int $maxFailures = 5,
        private readonly int $lockSeconds = 900,
    ) {
    }

    /**
     * @throws HttpException 429 si l'IP ou le compte est verrouillé (détail « reessayer_dans » en secondes)
     */
    public function check(string $ip, int $now): void
    {
        $until = (int) $this->db->value(
            'SELECT coalesce(max(jusqu_a), 0) FROM tentatives WHERE cle IN (?, ?)',
            [self::ipKey($ip), self::ACCOUNT_KEY],
        );
        if ($until > $now) {
            $minutes = (int) ceil(($until - $now) / 60);
            throw new HttpException(429, sprintf('Trop de tentatives : réessayez dans %d min', $minutes), ['reessayer_dans' => $until - $now]);
        }
    }

    /**
     * @return bool vrai si cet échec vient de déclencher un verrouillage (à journaliser)
     */
    public function recordFailure(string $ip, int $now): bool
    {
        return $this->db->transaction(function () use ($ip, $now): bool {
            $this->purge($now);
            $locked = false;
            foreach ([self::ipKey($ip), self::ACCOUNT_KEY] as $key) {
                $entry = $this->db->one('SELECT echecs, jusqu_a FROM tentatives WHERE cle = ?', [$key]);
                $failures = (int) ($entry['echecs'] ?? 0) + 1;
                $until = $entry['jusqu_a'] ?? null;
                if ($failures % $this->maxFailures === 0) {
                    $lockCount = intdiv($failures, $this->maxFailures);
                    $until = $now + min(self::MAX_LOCK_SECONDS, $this->lockSeconds * 2 ** ($lockCount - 1));
                    $locked = true;
                }
                $this->db->run(
                    'INSERT INTO tentatives (cle, echecs, dernier, jusqu_a) VALUES (?, ?, ?, ?)
                     ON CONFLICT (cle) DO UPDATE SET echecs = excluded.echecs, dernier = excluded.dernier, jusqu_a = excluded.jusqu_a',
                    [$key, $failures, $now, $until],
                );
            }

            return $locked;
        });
    }

    public function recordSuccess(string $ip, int $now): void
    {
        $this->db->transaction(function () use ($ip, $now): void {
            $this->purge($now);
            $this->db->run('DELETE FROM tentatives WHERE cle IN (?, ?)', [self::ipKey($ip), self::ACCOUNT_KEY]);
        });
    }

    /**
     * Lève tous les verrouillages (php api/bin/compte.php debloquer).
     */
    public function reset(): void
    {
        $this->db->run('DELETE FROM tentatives');
    }

    private static function ipKey(string $ip): string
    {
        return 'ip:' . $ip;
    }

    private function purge(int $now): void
    {
        $this->db->run(
            'DELETE FROM tentatives WHERE dernier <= ? AND coalesce(jusqu_a, 0) <= ?',
            [$now - self::FORGET_AFTER, $now],
        );
    }
}
