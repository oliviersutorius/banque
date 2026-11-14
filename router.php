<?php

/**
 * Point d'entrée du serveur intégré PHP : php -S localhost:8000 router.php
 *
 * - /api/... est délégué à api/index.php
 * - les fichiers existants de front/dist sont servis tels quels
 * - toute autre URL renvoie front/dist/index.html (routage côté React)
 *
 * Lit aussi .env.local (variables BANQUE_* du poste local, voir .env.local.example). Ce fichier n'a d'effet qu'ici :
 * en ligne, le serveur web appelle directement api/index.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';

try {
    Banque\EnvFile::load(__DIR__ . '/.env.local');
} catch (RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage(), "\n";
    return true;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

if ($path === '/api' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

$dist = __DIR__ . '/front/dist';
$distReal = realpath($dist);

if ($distReal === false) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Le front n'est pas construit. Lancez : cd front && npm install && npm run build\n";
    return true;
}

$file = realpath($distReal . $path);

// Fichier statique, uniquement s'il est bien à l'intérieur de front/dist
if ($file !== false && is_file($file) && str_starts_with($file, $distReal . DIRECTORY_SEPARATOR)) {
    $types = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'json' => 'application/json',
        'woff2' => 'font/woff2',
    ];
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

header('Content-Type: text/html; charset=utf-8');
readfile($distReal . '/index.html');
return true;
