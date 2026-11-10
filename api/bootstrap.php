<?php

declare(strict_types=1);

/**
 * Chargement automatique des classes Banque\... depuis api/src/.
 * Partagé par l'API et le lanceur de tests.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Banque\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
