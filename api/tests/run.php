<?php

/**
 * Lanceur de tests maison : php api/tests/run.php [filtre]
 *
 * Exécute toutes les classes *Test.php du dossier, affiche les échecs
 * et renvoie un code de sortie non nul si un test échoue.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestCase.php';

use Banque\Tests\AssertionFailed;
use Banque\Tests\TestCase;

$filter = $argv[1] ?? '';
$passed = 0;
$failures = [];

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require_once $file;
    $class = 'Banque\\Tests\\' . basename($file, '.php');
    if (!is_subclass_of($class, TestCase::class)) {
        continue;
    }
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test') || ($filter !== '' && !str_contains($class . '::' . $method, $filter))) {
            continue;
        }
        $name = basename($file, '.php') . '::' . $method;
        try {
            (new $class())->$method();
            $passed++;
            echo '.';
        } catch (AssertionFailed $e) {
            $failures[] = $name . "\n      " . $e->getMessage();
            echo 'F';
        } catch (Throwable $e) {
            $failures[] = $name . "\n      " . $e::class . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            echo 'E';
        }
    }
}

echo "\n\n";
foreach ($failures as $i => $failure) {
    echo ($i + 1) . ') ' . $failure . "\n\n";
}
printf("%d réussi(s), %d échec(s)\n", $passed, count($failures));
exit($failures === [] ? 0 : 1);
