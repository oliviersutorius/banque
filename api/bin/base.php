<?php

/**
 * Entretien de la base SQLite.
 *
 *   php api/bin/base.php sauvegarder   copie cohérente dans base/sauvegardes/ (20 gardées)
 *   php api/bin/base.php verifier      intégrité (PRAGMA integrity_check) et clés étrangères
 *   php api/bin/base.php optimiser     VACUUM et ANALYZE (à lancer de temps en temps, après une sauvegarde)
 *
 * Variable BANQUE_BASE : fichier SQLite (défaut base/banque.sqlite).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Banque\Database;
use Banque\Paths;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

umask(0077);

try {
    $paths = Paths::fromEnvironment(dirname(__DIR__, 2));
    $db = Database::open($paths);

    switch ($argv[1] ?? '') {
        case 'sauvegarder':
            echo 'Sauvegarde : ', $db->backup($paths->backupsDir()), "\n";
            break;

        case 'verifier':
            $integrity = (string) $db->value('PRAGMA integrity_check');
            $foreignKeys = $db->all('PRAGMA foreign_key_check');
            echo 'Schéma : version ', $db->schemaVersion(), "\n";
            echo 'Intégrité : ', $integrity, "\n";
            echo 'Clés étrangères : ', $foreignKeys === [] ? 'ok' : count($foreignKeys) . ' erreur(s)', "\n";
            foreach (['categories', 'operations', 'regles', 'parametres', 'sessions'] as $table) {
                printf("  %-11s %d lignes\n", $table, (int) $db->value('SELECT count(*) FROM ' . $table));
            }
            exit($integrity === 'ok' && $foreignKeys === [] ? 0 : 1);

        case 'optimiser':
            $before = filesize($paths->database);
            $db->run('VACUUM');
            $db->run('ANALYZE');
            clearstatcache();
            printf("Optimisée : %d Ko → %d Ko\n", intdiv((int) $before, 1024), intdiv((int) filesize($paths->database), 1024));
            break;

        default:
            fwrite(STDERR, "Usage : php api/bin/base.php sauvegarder|verifier|optimiser\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
    exit(1);
}
