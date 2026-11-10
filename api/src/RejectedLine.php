<?php

declare(strict_types=1);

namespace Banque;

/**
 * Une ligne du CSV qui ressemble à une opération mais n'a pas pu être lue : elle est signalée à l'écran.
 */
final class RejectedLine
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $content,
        public readonly string $reason,
    ) {
    }
}
