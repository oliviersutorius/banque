<?php

/**
 * Génère la clé de chiffrement du secret TOTP (32 octets, base64).
 *
 *   php api/bin/cle.php                    affiche une clé (pour BANQUE_CLE)
 *   php api/bin/cle.php /etc/banque/cle    l'écrit dans ce fichier, en 0400 (pour BANQUE_CLE_FICHIER)
 *
 * Ne jamais remplacer la clé d'un compte existant : le secret TOTP deviendrait illisible
 * (il faudrait alors refaire « php api/bin/compte.php totp-reset »).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Banque\SecretBox;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$key = SecretBox::generateKey();
$file = $argv[1] ?? null;
if ($file === null) {
    echo $key, "\n";
    exit(0);
}
if (file_exists($file)) {
    fwrite(STDERR, "Le fichier $file existe déjà : il n'est pas remplacé (le secret TOTP chiffré avec deviendrait illisible).\n");
    exit(1);
}
umask(0277);
if (file_put_contents($file, $key . "\n") === false) {
    fwrite(STDERR, "Écriture impossible : $file\n");
    exit(1);
}
chmod($file, 0400);
echo "Clé écrite dans $file (0400). À déclarer dans BANQUE_CLE_FICHIER.\n";
echo "Le fichier doit appartenir à l'utilisateur de PHP-FPM, et être sauvegardé à part de data/.\n";
