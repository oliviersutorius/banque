<?php

declare(strict_types=1);

namespace Banque;

/**
 * Libellé découpé et nettoyé, utilisé uniquement pour la catégorisation.
 */
final class NormalizedLabel
{
    public function __construct(
        /** Code du type d'opération : CARTE, PRELEVEMENT, VIREMENT_RECU… ou AUTRE */
        public readonly string $type,
        /** Type tel qu'écrit dans le libellé, forme canonique : « PAIEMENT PAR CARTE » (vide si AUTRE) */
        public readonly string $typeLabel,
        /** Détail nettoyé : « AUCHAN PEROLS » */
        public readonly string $merchant,
    ) {
    }

    /**
     * Texte complet sur lequel les motifs des règles sont recherchés.
     */
    public function text(): string
    {
        return trim($this->typeLabel . ' ' . $this->merchant);
    }
}
