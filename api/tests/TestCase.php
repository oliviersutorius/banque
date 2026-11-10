<?php

declare(strict_types=1);

namespace Banque\Tests;

use RuntimeException;
use Throwable;

final class AssertionFailed extends RuntimeException
{
}

/**
 * Base des tests : chaque méthode publique commençant par « test » est exécutée.
 */
abstract class TestCase
{
    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                "%sattendu : %s\n      obtenu  : %s",
                $message !== '' ? $message . "\n      " : '',
                var_export($expected, true),
                var_export($actual, true),
            ));
        }
    }

    protected function assertTrue(bool $condition, string $message = 'la condition est fausse'): void
    {
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    /**
     * @param class-string<Throwable> $class
     */
    protected function assertThrows(string $class, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            throw new AssertionFailed('exception ' . $class . ' attendue, ' . $e::class . ' levée : ' . $e->getMessage());
        }
        throw new AssertionFailed('exception ' . $class . ' attendue, aucune levée');
    }
}
