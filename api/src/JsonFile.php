<?php

declare(strict_types=1);

namespace Banque;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * Lecture et écriture des fichiers JSON de data/.
 *
 * Écriture atomique : verrou exclusif (flock), fichier temporaire puis rename.
 * Avant d'écraser un fichier existant, une copie horodatée est faite dans le dossier des sauvegardes.
 */
final class JsonFile
{
    public const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    private const BACKUPS_KEPT = 20;

    public function __construct(private readonly string $backupsDir)
    {
    }

    /**
     * @return array<mixed>
     * @throws RuntimeException si le fichier est illisible ou n'est pas un objet/tableau JSON
     */
    public function read(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Lecture impossible : ' . basename($path));
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('JSON invalide dans ' . basename($path) . ' : ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new RuntimeException(basename($path) . ' : objet ou tableau JSON attendu');
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     * @param bool $backup false pour un fichier technique réécrit souvent (tentatives de connexion)
     */
    public function write(string $path, array $data, bool $backup = true): void
    {
        $this->withLock($path, function () use ($path, $data, $backup): void {
            $this->writeLocked($path, $data, $backup);
        });
    }

    /**
     * Lecture, modification et écriture sous un même verrou : deux requêtes simultanées ne peuvent pas
     * partir du même contenu (par exemple réutiliser deux fois le même code de connexion).
     *
     * @param callable(array<mixed>|null): (array<mixed>|null) $change reçoit le contenu actuel (null si le fichier
     *        n'existe pas) et renvoie le nouveau contenu, ou null pour ne rien écrire
     * @return array<mixed>|null le contenu écrit, ou null si rien n'a été écrit
     */
    public function update(string $path, callable $change, bool $backup = true): ?array
    {
        return $this->withLock($path, function () use ($path, $change, $backup): ?array {
            $next = $change(is_file($path) ? $this->read($path) : null);
            if ($next !== null) {
                $this->writeLocked($path, $next, $backup);
            }

            return $next;
        });
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withLock(string $path, callable $fn): mixed
    {
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Verrouillage impossible : ' . basename($path));
        }
        try {
            return $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function writeLocked(string $path, array $data, bool $backup): void
    {
        $json = json_encode($data, self::FLAGS | JSON_THROW_ON_ERROR) . "\n";
        if ($backup && is_file($path)) {
            $this->backup($path);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $path)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            throw new RuntimeException('Écriture impossible : ' . basename($path));
        }
    }

    /**
     * Écrit le fichier seulement s'il n'existe pas encore.
     *
     * @param array<mixed> $data
     */
    public function initialize(string $path, array $data): void
    {
        if (!is_file($path)) {
            $this->write($path, $data);
        }
    }

    private function backup(string $path): void
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        $stamp = (new DateTimeImmutable())->format('Ymd-His-u');
        if (!copy($path, $this->backupsDir . '/' . $name . '-' . $stamp . '.json')) {
            throw new RuntimeException('Sauvegarde impossible : ' . basename($path));
        }

        $backups = glob($this->backupsDir . '/' . $name . '-*.json') ?: [];
        sort($backups);
        foreach (array_slice($backups, 0, max(0, count($backups) - self::BACKUPS_KEPT)) as $old) {
            @unlink($old);
        }
    }
}
