<?php

declare(strict_types=1);

namespace Banque;

/**
 * Petites fonctions de texte partagées : accents, espaces, majuscules.
 */
final class Text
{
    private const ACCENTS = [
        'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ã' => 'A', 'Å' => 'A',
        'Ç' => 'C',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Î' => 'I', 'Ï' => 'I', 'Í' => 'I', 'Ì' => 'I',
        'Ñ' => 'N',
        'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O',
        'Û' => 'U', 'Ü' => 'U', 'Ù' => 'U', 'Ú' => 'U',
        'Ÿ' => 'Y', 'Ý' => 'Y',
        'Œ' => 'OE', 'Æ' => 'AE',
    ];

    /**
     * Remplace toute suite de blancs (y compris retours à la ligne et espaces insécables) par un espace.
     */
    public static function collapseSpaces(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
    }

    /**
     * Majuscules, sans accents, espaces réduits : la forme utilisée pour comparer des libellés.
     */
    public static function canonical(string $text): string
    {
        return self::collapseSpaces(strtr(mb_strtoupper($text, 'UTF-8'), self::ACCENTS));
    }
}
