<?php

declare(strict_types=1);

namespace Banque;

use RuntimeException;

/**
 * Erreur destinée au client : son code devient le statut HTTP de la réponse.
 */
final class HttpException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        int $status,
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode();
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
