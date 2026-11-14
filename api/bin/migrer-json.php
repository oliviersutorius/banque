<?php

/**
 * Migration de l'ancien stockage JSON (data/) vers la base SQLite, en trois temps :
 *
 *   php api/bin/migrer-json.php                simulation : lecture, validation et rapport, rien n'est écrit
 *   php api/bin/migrer-json.php --appliquer    crée la base, y copie tout en une transaction, puis vérifie
 *                                              qu'elle contient exactement les mêmes données (sinon elle est effacée)
 *   php api/bin/migrer-json.php --nettoyer     revérifie, archive data/ en .tar.gz dans base/archives/, puis, après
 *                                              confirmation, supprime les fichiers migrés : data/ ne garde que le
 *                                              journal, déplacé dans log/
 *
 * Options :
 *   --source=DOSSIER       ancien dossier de données (défaut : data/ à la racine du projet)
 *   --malgre-differences   avec --nettoyer : continuer même si la base a été modifiée depuis la migration (le site a
 *                          servi entre-temps) ; l'archive de data/ est créée et vérifiée avant toute suppression
 * Variables : BANQUE_BASE (fichier SQLite, défaut base/banque.sqlite), BANQUE_LOG (défaut log/).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Banque\Amount;
use Banque\Database;
use Banque\LegacyJsonMigration;
use Banque\Paths;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

umask(0077);

$root = dirname(__DIR__, 2);
$options = array_slice($argv, 1);
$source = $root . '/data';
foreach ($options as $option) {
    if (str_starts_with($option, '--source=')) {
        $source = rtrim(substr($option, 9), '/');
    }
}
$mode = in_array('--nettoyer', $options, true) ? 'nettoyer' : (in_array('--appliquer', $options, true) ? 'appliquer' : 'simulation');
$paths = Paths::fromEnvironment($root);

function line(string $text = ''): void
{
    echo $text, "\n";
}

/**
 * @param array<string, mixed> $report
 */
function printReport(array $report): void
{
    line(sprintf('Catégories : %d (dont %d sous-catégories de dépenses en plus)', $report['catégories'], $report['sous_catégories']));
    line(sprintf('Règles     : %d', $report['règles']));
    line(sprintf('Opérations : %d sur %d mois', $report['lignes'], count($report['mois'])));
    foreach ($report['mois'] as $month => $info) {
        line(sprintf('  %s : %4d lignes, dépenses %12s, revenus %12s', $month, $info['lignes'], Amount::format($info['dépenses']), Amount::format($info['revenus'])));
    }
    line('Compte     : ' . ($report['compte'] ? 'oui' : 'non'));
    line(sprintf('Tentatives : %d compteur(s)', $report['tentatives']));
    if ($report['anomalies'] !== []) {
        line();
        line('ANOMALIES (à corriger avant la migration) :');
        foreach ($report['anomalies'] as $anomaly) {
            line('  - ' . $anomaly);
        }
    }
}

function removeDatabase(string $path): void
{
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

try {
    if (!is_file($source . '/categories.json')) {
        throw new RuntimeException("Rien à migrer : $source/categories.json est absent");
    }
    $migration = new LegacyJsonMigration($source);
    line("Source : $source");
    line("Base   : {$paths->database}");
    line();
    $report = $migration->read();
    printReport($report);
    if ($report['anomalies'] !== []) {
        exit(1);
    }

    if ($mode === 'simulation') {
        line();
        line('Simulation terminée : rien n\'a été écrit. Pour migrer : php api/bin/migrer-json.php --appliquer');
        exit(0);
    }

    if ($mode === 'appliquer') {
        if (is_file($paths->database)) {
            throw new RuntimeException("La base {$paths->database} existe déjà : migration refusée (rien n'a été modifié)");
        }
        $paths->ensureDirectories();
        $db = new Database($paths->database);
        try {
            $db->migrate($paths->schemaDir());
            $migration->import($db);
            $differences = $migration->compare($db);
            $integrity = $db->value('PRAGMA integrity_check');
            $foreignKeys = $db->all('PRAGMA foreign_key_check');
        } catch (Throwable $e) {
            unset($db);
            removeDatabase($paths->database);
            throw $e;
        }
        if ($differences !== [] || $integrity !== 'ok' || $foreignKeys !== []) {
            unset($db);
            removeDatabase($paths->database);
            line();
            line('ÉCHEC DE LA VÉRIFICATION : la base a été effacée, data/ est intact.');
            foreach ($differences as $difference) {
                line('  - ' . $difference);
            }
            exit(1);
        }
        line();
        line('Migration réussie et vérifiée : la base contient exactement les données des fichiers.');
        line('Les fichiers de data/ sont intacts. Après avoir contrôlé le site, pour les archiver et les supprimer :');
        line('  php api/bin/migrer-json.php --nettoyer');
        exit(0);
    }

    // --nettoyer
    if (!is_file($paths->database)) {
        throw new RuntimeException('Base absente : lancer d\'abord php api/bin/migrer-json.php --appliquer');
    }
    $db = new Database($paths->database);
    $differences = $migration->compare($db);
    if ($differences !== []) {
        line();
        line('La base ne correspond plus exactement aux fichiers (le site a servi depuis la migration ?) :');
        foreach ($differences as $difference) {
            line('  - ' . $difference);
        }
        if (!in_array('--malgre-differences', $options, true)) {
            line('Rien n\'est supprimé. Si ces modifications viennent de votre utilisation du site, relancer avec :');
            line('  php api/bin/migrer-json.php --nettoyer --malgre-differences');
            exit(1);
        }
        line('--malgre-differences : on continue, data/ sera d\'abord archivé.');
    } else {
        line();
        line('Base conforme aux fichiers.');
    }
    $archive = $migration->archive($paths->archivesDir());
    line("Archive créée et vérifiée : $archive");
    line();
    line('Vont être supprimés de ' . $source . ' : mois/, categories.json, regles.json, parametres.json, compte.json,');
    line('tentatives.json, les fichiers .lock, sessions/ et sauvegardes/. Le journal des connexions sera déplacé dans');
    line($paths->logDir . '/.');
    echo 'Confirmer la suppression ? (oui/non) ';
    $answer = strtolower(trim((string) fgets(STDIN)));
    if ($answer !== 'oui') {
        line('Rien n\'a été supprimé (l\'archive est conservée).');
        exit(0);
    }
    $result = $migration->cleanup($paths->logDir);
    line('Supprimés : ' . implode(', ', $result['supprimés']));
    if ($result['journal'] !== null) {
        line('Journal des connexions : ' . $result['journal']);
    }
    if ($result['inconnus'] !== []) {
        line("Fichiers inconnus laissés dans $source (à vérifier) :");
        foreach ($result['inconnus'] as $file) {
            line('  - ' . $file);
        }
    } else {
        line("$source a été supprimé : il ne contenait plus rien.");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
    exit(1);
}
