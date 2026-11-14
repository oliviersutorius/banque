<?php

declare(strict_types=1);

namespace Banque;

use RuntimeException;

/**
 * Emplacements des fichiers de l'application :
 *
 * - base SQLite : base/banque.sqlite (BANQUE_BASE), avec base/sauvegardes/ et base/archives/ à côté ;
 * - journaux : log/ (BANQUE_LOG), dont log/connexions.log ;
 * - ancien dossier data/ (fichiers JSON d'avant la base), lu seulement par la migration. Il n'est pris en compte
 *   qu'avec l'emplacement par défaut de la base : une base d'essai (BANQUE_BASE) ne dépend pas de data/.
 */
final class Paths
{
    public function __construct(
        public readonly string $database,
        public readonly string $logDir,
        public readonly ?string $legacyDataDir,
    ) {
    }

    /**
     * @param string $root racine du projet
     */
    public static function fromEnvironment(string $root): self
    {
        $database = getenv('BANQUE_BASE') ?: null;

        return new self(
            $database ?? $root . '/base/banque.sqlite',
            getenv('BANQUE_LOG') ?: $root . '/log',
            $database === null ? $root . '/data' : null,
        );
    }

    public function baseDir(): string
    {
        return dirname($this->database);
    }

    public function backupsDir(): string
    {
        return $this->baseDir() . '/sauvegardes';
    }

    public function archivesDir(): string
    {
        return $this->baseDir() . '/archives';
    }

    public function connectionLog(): string
    {
        return $this->logDir . '/connexions.log';
    }

    public function schemaDir(): string
    {
        return dirname(__DIR__) . '/schema';
    }

    /**
     * Ancien stockage JSON encore présent et pas encore migré : l'API doit refuser de démarrer sur une base vide.
     */
    public function migrationPending(): bool
    {
        return $this->legacyDataDir !== null && !is_file($this->database) && is_file($this->legacyDataDir . '/categories.json');
    }

    public function ensureDirectories(): void
    {
        foreach ([$this->baseDir(), $this->backupsDir(), $this->logDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('Impossible de créer le dossier ' . $dir);
            }
        }
    }
}
