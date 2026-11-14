<?php

declare(strict_types=1);

namespace Banque;

/**
 * Limitation des tentatives de connexion, stockée dans data/tentatives.json.
 *
 * Deux compteurs : un par adresse IP (« ip:<adresse> ») et un global pour le compte (« compte »), qui freine aussi
 * une attaque répartie sur beaucoup d'adresses. Tous les $maxFailures échecs, le compteur est verrouillé, d'abord
 * $lockSeconds (15 min), puis le double à chaque nouveau verrouillage, au plus 24 h. Une connexion réussie remet
 * à zéro le compteur de son IP et celui du compte ; un compteur sans échec depuis 24 h est oublié.
 *
 * Format : { "ip:203.0.113.5": { "echecs": 3, "dernier": 1763130000, "jusqu_a": null }, "compte": {...} }
 */
final class LoginThrottle
{
    public const ACCOUNT_KEY = 'compte';
    private const MAX_LOCK_SECONDS = 86400;
    private const FORGET_AFTER = 86400;

    public function __construct(
        private readonly string $path,
        private readonly JsonFile $json,
        private readonly int $maxFailures = 5,
        private readonly int $lockSeconds = 900,
    ) {
    }

    /**
     * @throws HttpException 429 si l'IP ou le compte est verrouillé (détail « reessayer_dans » en secondes)
     */
    public function check(string $ip, int $now): void
    {
        $data = is_file($this->path) ? $this->json->read($this->path) : [];
        $until = 0;
        foreach ([self::ipKey($ip), self::ACCOUNT_KEY] as $key) {
            $entry = $data[$key] ?? null;
            if (is_array($entry) && is_int($entry['jusqu_a'] ?? null)) {
                $until = max($until, $entry['jusqu_a']);
            }
        }
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
        $locked = false;
        $this->json->update($this->path, function (?array $data) use ($ip, $now, &$locked): array {
            $data = $this->purge($data ?? [], $now);
            foreach ([self::ipKey($ip), self::ACCOUNT_KEY] as $key) {
                $entry = is_array($data[$key] ?? null) ? $data[$key] : ['echecs' => 0];
                $failures = (int) ($entry['echecs'] ?? 0) + 1;
                $until = is_int($entry['jusqu_a'] ?? null) ? $entry['jusqu_a'] : null;
                if ($failures % $this->maxFailures === 0) {
                    $lockCount = intdiv($failures, $this->maxFailures);
                    $until = $now + min(self::MAX_LOCK_SECONDS, $this->lockSeconds * 2 ** ($lockCount - 1));
                    $locked = true;
                }
                $data[$key] = ['echecs' => $failures, 'dernier' => $now, 'jusqu_a' => $until];
            }

            return $data;
        }, false);

        return $locked;
    }

    public function recordSuccess(string $ip, int $now): void
    {
        $this->json->update($this->path, function (?array $data) use ($ip, $now): array {
            $data = $this->purge($data ?? [], $now);
            unset($data[self::ipKey($ip)], $data[self::ACCOUNT_KEY]);

            return $data;
        }, false);
    }

    /**
     * Lève tous les verrouillages (php api/bin/compte.php debloquer).
     */
    public function reset(): void
    {
        $this->json->write($this->path, [], false);
    }

    private static function ipKey(string $ip): string
    {
        return 'ip:' . $ip;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function purge(array $data, int $now): array
    {
        return array_filter(
            $data,
            static fn (mixed $entry): bool => is_array($entry)
                && ((int) ($entry['dernier'] ?? 0) > $now - self::FORGET_AFTER || (int) ($entry['jusqu_a'] ?? 0) > $now),
        );
    }
}
