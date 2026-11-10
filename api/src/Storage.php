<?php

declare(strict_types=1);

namespace Banque;

use RuntimeException;

/**
 * Emplacements du dossier data/ et création de son arborescence au premier lancement.
 */
final class Storage
{
    public function __construct(private readonly string $root)
    {
    }

    public function ensureDirectories(): void
    {
        foreach ([$this->root, $this->monthsDir(), $this->backupsDir()] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('Impossible de créer le dossier ' . $dir);
            }
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    public function monthsDir(): string
    {
        return $this->root . '/mois';
    }

    public function backupsDir(): string
    {
        return $this->root . '/sauvegardes';
    }

    public function categoriesFile(): string
    {
        return $this->root . '/categories.json';
    }

    public function rulesFile(): string
    {
        return $this->root . '/regles.json';
    }

    public function settingsFile(): string
    {
        return $this->root . '/parametres.json';
    }
}
