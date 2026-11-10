<?php

declare(strict_types=1);

namespace Banque;

use InvalidArgumentException;

/**
 * Conversion des montants entre le texte (« 2 640,31 », « 69,9 ») et les centimes entiers.
 */
final class Amount
{
    /**
     * Lit un montant du CSV : virgule décimale, 0 à 2 décimales, espaces de milliers tolérés.
     *
     * @throws InvalidArgumentException si le texte n'est pas un montant positif valide
     */
    public static function parse(string $text): int
    {
        $compact = (string) preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $text);
        if (preg_match('/^(\d+)(?:,(\d{1,2}))?$/', $compact, $m) !== 1) {
            throw new InvalidArgumentException('Montant invalide : « ' . $text . ' »');
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /**
     * Écrit un montant au format du JSON : « 69,90 » (toujours 2 décimales, sans séparateur de milliers).
     */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d,%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
