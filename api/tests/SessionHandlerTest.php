<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\Database;
use Banque\DatabaseSessionHandler;

final class SessionHandlerTest extends TestCase
{
    private readonly Database $db;
    private int $now = 1_700_000_000;
    private readonly DatabaseSessionHandler $handler;

    public function __construct()
    {
        $this->db = self::memoryDatabase();
        $this->handler = new DatabaseSessionHandler($this->db, 3600, fn (): int => $this->now);
    }

    public function testEcritRelitEtDetruit(): void
    {
        $this->assertSame('', $this->handler->read('abc'), 'session inconnue : vide');
        $this->assertTrue(!$this->handler->validateId('abc'), 'identifiant inconnu refusé (mode strict)');

        $this->handler->write('abc', 'etape|s:8:"connecte";');
        $this->assertSame('etape|s:8:"connecte";', $this->handler->read('abc'));
        $this->assertTrue($this->handler->validateId('abc'));
        $this->handler->write('abc', 'etape|s:4:"code";');
        $this->assertSame('etape|s:4:"code";', $this->handler->read('abc'), 'réécriture');

        $this->handler->destroy('abc');
        $this->assertSame('', $this->handler->read('abc'));
        $this->assertSame(0, $this->db->value('SELECT count(*) FROM sessions'));
    }

    public function testExpirationEtPurge(): void
    {
        $this->handler->write('ancienne', 'x');
        $this->now += 1800;
        $this->handler->write('recente', 'y');
        $this->now += 1800;

        $this->assertSame('', $this->handler->read('ancienne'), 'expirée');
        $this->assertTrue(!$this->handler->validateId('ancienne'));
        $this->assertSame('y', $this->handler->read('recente'));
        $this->assertSame(1, $this->handler->gc(3600));
        $this->assertSame(['recente'], array_column($this->db->all('SELECT id FROM sessions'), 'id'));

        $this->handler->updateTimestamp('recente', 'y');
        $this->now += 3000;
        $this->assertSame('y', $this->handler->read('recente'), 'expiration repoussée sans réécriture');
    }
}
