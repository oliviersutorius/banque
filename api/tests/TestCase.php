<?php

declare(strict_types=1);

namespace Banque\Tests;

use RuntimeException;
use Throwable;

final class AssertionFailed extends RuntimeException
{
}

final class TestSkipped extends RuntimeException
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

    protected function markSkipped(string $reason): never
    {
        throw new TestSkipped($reason);
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

/**
 * Dossier temporaire supprimé avec son contenu quand l'objet est détruit.
 */
final class TempDir
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/banque-test-' . bin2hex(random_bytes(6));
        mkdir($this->path, 0700, true);
    }

    public function __destruct()
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->path);
    }
}

/**
 * Session en mémoire pour tester Auth sans sessions PHP (impossible une fois la sortie commencée).
 */
final class ArraySession implements \Banque\SessionStore
{
    /** @var array<string, mixed> */
    public array $data = [];
    public int $regenerations = 0;
    public int $destructions = 0;

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function regenerate(): void
    {
        $this->regenerations++;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->destructions++;
    }
}
