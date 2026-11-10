<?php

declare(strict_types=1);

namespace Banque;

/**
 * Normalisation d'un libellé Crédit Agricole :
 * « Paiement par carte PAIEMENT PAR CARTE X9876 AUCHAN PEROLS 246709 10/01 » → CARTE + « AUCHAN PEROLS »
 *
 * 1. extraction du type d'opération (début du libellé)
 * 2. suppression du type répété en majuscules
 * 3. suppression du numéro de carte, des dates, des heures, des identifiants créancier SEPA
 *    et des références (mots contenant 5 chiffres ou plus)
 * 4. majuscules, sans accents, espaces réduits
 */
final class LabelNormalizer
{
    public const UNKNOWN_TYPE = 'AUTRE';

    /** Type tel qu'écrit dans le libellé (forme canonique) => code */
    private const TYPES = [
        'PAIEMENT PAR CARTE' => 'CARTE',
        'VIREMENT EN VOTRE FAVEUR' => 'VIREMENT_RECU',
        'VIREMENT RECU' => 'VIREMENT_RECU',
        'VIREMENT EMIS' => 'VIREMENT_EMIS',
        'PRELEVEMENT' => 'PRELEVEMENT',
        'REGLEMENT' => 'REGLEMENT',
        'RETRAIT AU DISTRIBUTEUR' => 'RETRAIT',
        'REMISE DE CHEQUE' => 'REMISE_CHEQUE',
        'CHEQUE' => 'CHEQUE',
        'FRAIS' => 'FRAIS',
        'COTISATION' => 'COTISATION',
        'INTERETS' => 'INTERETS',
        'REMBOURSEMENT DE PRET' => 'REMBOURSEMENT_PRET',
        'REJET' => 'REJET',
        'REGUL' => 'REGULARISATION',
        'AVOIR' => 'AVOIR',
    ];

    private const NOISE_PATTERNS = [
        '/\bX\d{4}\b/',                                  // numéro de carte : X1234
        '#\b\d{2}/\d{2}/(?:\d{4}|\d{2})\b#',             // dates : 10/04/2025, 10/04/25
        '#\b\d{2}/\d{4}\b#',                             // échéances : 01/2026
        '#\b\d{2}/\d{2}\b#',                             // dates courtes : 29/01, 07/25
        '/\b\d{1,2}H\d{2}\b/',                           // heures : 08H31
        '/\b[A-Z]{2}\d{2}ZZZ[0-9A-Z]+\b/',               // identifiant créancier SEPA : FR22ZZZ885B55
    ];

    /**
     * @return list<string> codes de type d'opération possibles, AUTRE compris
     */
    public static function typeCodes(): array
    {
        return [...array_values(array_unique(self::TYPES)), self::UNKNOWN_TYPE];
    }

    public function normalize(string $libelle): NormalizedLabel
    {
        $text = Text::canonical($libelle);
        [$typeLabel, $rest] = $this->splitType($text);

        if ($typeLabel !== '') {
            // Le type est répété en majuscules juste après : on le retire une fois
            $rest = $this->stripPrefix($rest, $typeLabel) ?? $rest;
        }

        return new NormalizedLabel(
            $typeLabel === '' ? self::UNKNOWN_TYPE : self::TYPES[$typeLabel],
            $typeLabel,
            $this->clean($rest),
        );
    }

    /**
     * @return array{string, string} le type reconnu (ou '') et la suite du libellé
     */
    private function splitType(string $text): array
    {
        $types = array_keys(self::TYPES);
        // Le type le plus long d'abord : « REMISE DE CHEQUE » avant « CHEQUE »
        usort($types, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($types as $type) {
            $rest = $this->stripPrefix($text, $type);
            if ($rest !== null) {
                return [$type, $rest];
            }
        }

        return ['', $text];
    }

    /**
     * Retire $prefix du début de $text s'il y forme un mot entier, sinon null.
     */
    private function stripPrefix(string $text, string $prefix): ?string
    {
        if ($text === $prefix) {
            return '';
        }
        if (str_starts_with($text, $prefix . ' ')) {
            return substr($text, strlen($prefix) + 1);
        }

        return null;
    }

    private function clean(string $text): string
    {
        $text = (string) preg_replace(self::NOISE_PATTERNS, ' ', $text);

        $words = array_filter(
            explode(' ', Text::collapseSpaces($text)),
            // Références (mots avec 5 chiffres consécutifs ou plus) et ponctuation isolée
            static fn (string $w): bool => preg_match('/\d{5,}/', $w) !== 1 && preg_match('/[\p{L}\d]/u', $w) === 1,
        );

        return implode(' ', $words);
    }
}
