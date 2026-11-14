<?php

declare(strict_types=1);

namespace Banque;

use RuntimeException;

/**
 * Fichier de variables du poste local (.env.local), lu uniquement par router.php (serveur « php -S »).
 *
 * Sur le serveur en ligne, Caddy appelle api/index.php sans passer par router.php : ce fichier n'y a aucun effet.
 *
 * Format : une ligne NOM=valeur par variable, lignes vides et commentaires (#) ignorés, guillemets facultatifs autour
 * de la valeur. Seules les variables BANQUE_* sont acceptées. Une variable déjà définie dans l'environnement (shell)
 * garde sa valeur.
 */
final class EnvFile
{
    /**
     * @return array<string, string>
     * @throws RuntimeException si une ligne est mal formée ou ne concerne pas une variable BANQUE_*
     */
    public static function parse(string $content): array
    {
        $variables = [];
        foreach (preg_split('/\R/', $content) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m) !== 1) {
                throw new RuntimeException(sprintf('.env.local, ligne %d : « NOM=valeur » attendu', $number + 1));
            }
            if (!str_starts_with($m[1], 'BANQUE_')) {
                throw new RuntimeException(sprintf('.env.local, ligne %d : seules les variables BANQUE_* sont acceptées (%s)', $number + 1, $m[1]));
            }
            $value = $m[2];
            if (preg_match('/^(["\'])(.*)\1$/', $value, $quoted) === 1) {
                $value = $quoted[2];
            }
            $variables[$m[1]] = $value;
        }

        return $variables;
    }

    /**
     * Charge le fichier s'il existe.
     *
     * @return list<string> les variables effectivement définies (celles du shell sont conservées)
     * @throws RuntimeException si le fichier est illisible ou mal formé
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $content = is_readable($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new RuntimeException('Lecture impossible : ' . $path);
        }
        $loaded = [];
        foreach (self::parse($content) as $name => $value) {
            if (getenv($name) === false) {
                putenv($name . '=' . $value);
                $loaded[] = $name;
            }
        }

        return $loaded;
    }
}
