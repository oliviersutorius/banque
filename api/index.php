<?php

/**
 * Routeur de l'API. Toutes les réponses sont en JSON.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Banque\HttpException;
use Banque\Router;
use Banque\Storage;

const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

function sendJson(int $status, mixed $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_FLAGS | JSON_THROW_ON_ERROR);
}

/**
 * Gestionnaire provisoire des routes qui seront codées aux étapes suivantes.
 */
function notImplemented(string $step): callable
{
    return static function () use ($step): never {
        throw new HttpException(501, 'Pas encore implémenté (' . $step . ')');
    };
}

$storage = new Storage(dirname(__DIR__) . '/data');
$router = new Router();

$router->add('GET', '/api/sante', static fn (): array => [
    'statut' => 'ok',
    'php' => PHP_VERSION,
    'données' => is_dir($storage->monthsDir()),
]);

// Étape 4 : import
$router->add('POST', '/api/import/preview', notImplemented('étape 4'));
$router->add('POST', '/api/import/confirm', notImplemented('étape 4'));

// Étapes 5 et 6 : mois et années
$router->add('GET', '/api/mois', notImplemented('étape 5'));
$router->add('GET', '/api/mois/{mois:\d{6}}', notImplemented('étape 5'));
$router->add('PATCH', '/api/mois/{mois:\d{6}}/lignes/{index:\d+}', notImplemented('étape 5'));
$router->add('GET', '/api/annee/{annee:\d{4}}', notImplemented('étape 6'));

// Étape 7 : catégories, règles, paramètres
$router->add('GET', '/api/categories', notImplemented('étape 7'));
$router->add('PUT', '/api/categories', notImplemented('étape 7'));
$router->add('GET', '/api/regles', notImplemented('étape 7'));
$router->add('POST', '/api/regles', notImplemented('étape 7'));
$router->add('PUT', '/api/regles/{index:\d+}', notImplemented('étape 7'));
$router->add('DELETE', '/api/regles/{index:\d+}', notImplemented('étape 7'));
$router->add('GET', '/api/parametres', notImplemented('étape 7'));
$router->add('PUT', '/api/parametres', notImplemented('étape 7'));

try {
    $storage->ensureDirectories();
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $result = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', is_string($path) ? rtrim($path, '/') : '');
    sendJson(200, $result);
} catch (HttpException $e) {
    sendJson($e->status(), ['erreur' => $e->getMessage()] + $e->details());
} catch (Throwable $e) {
    error_log((string) $e);
    sendJson(500, ['erreur' => 'Erreur interne du serveur']);
}
