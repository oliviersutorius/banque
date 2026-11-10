<?php

declare(strict_types=1);

namespace Banque;

final class ParseResult
{
    /**
     * @param list<ParsedLine> $lines opérations lues, dans l'ordre du fichier
     * @param list<RejectedLine> $rejected lignes signalées
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $rejected,
    ) {
    }
}
