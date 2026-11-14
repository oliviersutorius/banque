<?php

declare(strict_types=1);

namespace Banque;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Sessions PHP stockées dans la table « sessions ».
 *
 * Implémente aussi la validation d'identifiant (mode strict : un identifiant inconnu envoyé par le navigateur est
 * remplacé) et la mise à jour de l'expiration sans réécrire les données. Les sessions expirées sont purgées par le
 * ramasse-miettes de PHP (gc) ou à la lecture.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /**
     * @param \Closure(): int|null $clock heure courante (les tests la fixent)
     */
    public function __construct(
        private readonly Database $db,
        private readonly int $lifetime,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = $this->db->value('SELECT donnees FROM sessions WHERE id = ? AND expire_le > ?', [$id, $this->now()]);

        return is_string($data) ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        $this->db->run(
            'INSERT INTO sessions (id, donnees, expire_le) VALUES (?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET donnees = excluded.donnees, expire_le = excluded.expire_le',
            [$id, $data, $this->now() + $this->lifetime],
        );

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->run('DELETE FROM sessions WHERE id = ?', [$id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->db->run('DELETE FROM sessions WHERE expire_le <= ?', [$this->now()])->rowCount();
    }

    public function validateId(string $id): bool
    {
        return $this->db->value('SELECT 1 FROM sessions WHERE id = ? AND expire_le > ?', [$id, $this->now()]) !== null;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->db->run('UPDATE sessions SET expire_le = ? WHERE id = ?', [$this->now() + $this->lifetime, $id]);

        return true;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }
}
