<?php

declare(strict_types=1);

namespace Banque;

/**
 * Une opération lue dans le CSV, avant catégorisation.
 */
final class ParsedLine
{
    public function __construct(
        public readonly int $lineNumber,
        /** Date au format DD/MM/YYYY */
        public readonly string $date,
        /** Libellé d'origine, sauts de ligne et espaces multiples remplacés par un espace */
        public readonly string $libelle,
        public readonly Direction $direction,
        public readonly int $cents,
    ) {
    }

    /**
     * Mois de l'opération au format YYYYMM, qui détermine le fichier JSON.
     */
    public function month(): string
    {
        return substr($this->date, 6, 4) . substr($this->date, 3, 2);
    }
}
