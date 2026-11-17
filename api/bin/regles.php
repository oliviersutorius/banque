<?php

/**
 * Entretien des règles de catégorisation.
 *
 *   php api/bin/regles.php mutualiser                                  affiche les règles en double et les regroupements possibles
 *   php api/bin/regles.php mutualiser --appliquer                      supprime les règles en double (après une sauvegarde)
 *   php api/bin/regles.php mutualiser --appliquer --regroupements      supprime aussi les regroupements sans impact
 *
 * Rien n'est appliqué qui changerait la catégorie donnée par le moteur à une opération enregistrée.
 * Variable BANQUE_BASE : fichier SQLite (défaut base/banque.sqlite).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Banque\CategoryRepository;
use Banque\Database;
use Banque\LabelNormalizer;
use Banque\Paths;
use Banque\RuleConsolidation;
use Banque\RuleRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

umask(0077);

$label = static fn (array $rule): string => $rule['motif']
    . (isset($rule['et']) ? ' + ' . implode(' + ', $rule['et']) : '')
    . (isset($rule['type_operation']) ? ' [' . $rule['type_operation'] . ']' : '');
$category = static fn (array $rule): string => isset($rule['catégorie1']) ? $rule['catégorie1'] . ' > ' . $rule['catégorie2'] : (string) $rule['catégorie'];

try {
    if (($argv[1] ?? '') !== 'mutualiser') {
        fwrite(STDERR, "Usage : php api/bin/regles.php mutualiser [--appliquer [--regroupements]]\n");
        exit(1);
    }
    $apply = in_array('--appliquer', $argv, true);
    $withGroups = in_array('--regroupements', $argv, true);

    $paths = Paths::fromEnvironment(dirname(__DIR__, 2));
    $db = Database::open($paths);
    $categories = new CategoryRepository($db);
    $consolidation = new RuleConsolidation($db, new RuleRepository($db, $categories), $categories->catalog(), new LabelNormalizer(), $paths->backupsDir());
    $analysis = $consolidation->analyse();

    echo $analysis['règles'], " règles\n\n";
    echo 'Règles en double : ', count($analysis['doublons']), "\n";
    foreach ($analysis['doublons'] as $d) {
        printf("  %-8s %s  ← couverte par %s  (%s)\n", $d['clé'], $label($d['règle']), $label($d['couverte_par']), $category($d['règle']));
    }
    echo "\nRegroupements possibles : ", count($analysis['regroupements']), "\n";
    foreach ($analysis['regroupements'] as $g) {
        printf(
            "  %s → %s  (%s) : %s\n",
            implode(' + ', array_map($label, $g['règles'])),
            $label($g['nouvelle']),
            $category($g['nouvelle']),
            $g['impact'] === 0 ? 'sans impact' : 'changerait ' . $g['impact'] . ' opération(s), non applicable',
        );
        foreach ($g['exemples'] as $e) {
            echo '      ', $e['libelle'], ' : ', $e['avant'], ' → ', $e['après'], "\n";
        }
    }

    if (!$apply) {
        echo "\nRien n'a été modifié. Ajoutez --appliquer (et --regroupements) pour appliquer.\n";
        exit(0);
    }
    $keys = array_column($analysis['doublons'], 'clé');
    if ($withGroups) {
        $applicable = array_filter($analysis['regroupements'], static fn (array $g): bool => $g['impact'] === 0);
        $keys = [...$keys, ...array_column($applicable, 'clé')];
    }
    $result = $consolidation->apply($keys);
    echo "\n", $result['supprimées'], ' règle(s) supprimée(s), ', $result['créées'], " créée(s)\n";
    if ($result['sauvegarde'] !== null) {
        echo 'Sauvegarde : ', $result['sauvegarde'], "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
    exit(1);
}
