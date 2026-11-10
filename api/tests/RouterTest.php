<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\HttpException;
use Banque\Router;

final class RouterTest extends TestCase
{
    public function testExtraitLesParametres(): void
    {
        $router = new Router();
        $router->add('PATCH', '/api/mois/{mois:\d{6}}/lignes/{index:\d+}', static fn (array $p): array => $p);

        $this->assertSame(['mois' => '202601', 'index' => '3'], $router->dispatch('PATCH', '/api/mois/202601/lignes/3'));
    }

    public function testRefuseUnMoisMalFormeOuUnChemin(): void
    {
        $router = new Router();
        $router->add('GET', '/api/mois/{mois:\d{6}}', static fn (array $p): array => $p);

        $this->assertThrows(HttpException::class, static fn () => $router->dispatch('GET', '/api/mois/2026'));
        $this->assertThrows(HttpException::class, static fn () => $router->dispatch('GET', '/api/mois/../regles'));
    }

    public function testMethodeNonAutoriseeRenvoie405(): void
    {
        $router = new Router();
        $router->add('GET', '/api/mois', static fn (): array => []);

        try {
            $router->dispatch('DELETE', '/api/mois');
            $this->assertTrue(false, 'une exception était attendue');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status());
        }
    }
}
