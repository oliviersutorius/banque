<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\Database;
use Banque\Paths;
use RuntimeException;

final class DatabaseTest extends TestCase
{
    public function testReglagesEtVersionDuSchema(): void
    {
        $tmp = new TempDir();
        $db = new Database($tmp->path . '/banque.sqlite');
        $this->assertSame(0, $db->schemaVersion());
        $this->assertSame(1, $db->migrate(dirname(__DIR__) . '/schema'));
        $this->assertSame(1, $db->schemaVersion());
        $this->assertSame(0, $db->migrate(dirname(__DIR__) . '/schema'), 'rien à refaire');
        $this->assertSame(1, $db->value('PRAGMA foreign_keys'));
        $this->assertSame('wal', $db->value('PRAGMA journal_mode'));
        $this->assertSame(5000, $db->value('PRAGMA busy_timeout'));
    }

    public function testMigrationsSuivantesEtSauvegardeAvant(): void
    {
        $tmp = new TempDir();
        mkdir($tmp->path . '/schema');
        copy(dirname(__DIR__) . '/schema/001_initial.sql', $tmp->path . '/schema/001_initial.sql');
        $db = new Database(Database::MEMORY);
        $db->migrate($tmp->path . '/schema');

        file_put_contents($tmp->path . '/schema/002_essai.sql', 'CREATE TABLE essai (x INTEGER) STRICT; INSERT INTO essai VALUES (1);');
        $calls = 0;
        $this->assertSame(1, $db->migrate($tmp->path . '/schema', static function () use (&$calls): void {
            $calls++;
        }));
        $this->assertSame(1, $calls, 'sauvegarde appelée une fois');
        $this->assertSame(2, $db->schemaVersion());
        $this->assertSame(1, $db->value('SELECT x FROM essai'));

        file_put_contents($tmp->path . '/schema/003_casse.sql', 'CREATE TABLE autre (x INTEGER); INSERT INTO table_absente VALUES (1);');
        $this->assertThrows(\PDOException::class, static fn () => $db->migrate($tmp->path . '/schema'));
        $this->assertSame(2, $db->schemaVersion(), 'migration en échec annulée');
        $this->assertSame(null, $db->value("SELECT name FROM sqlite_master WHERE name = 'autre'"));
    }

    public function testTransactionAnnuleeEnCasDErreur(): void
    {
        $db = self::memoryDatabase();
        $this->assertThrows(RuntimeException::class, static fn () => $db->transaction(static function () use ($db): void {
            $db->run("INSERT INTO parametres VALUES ('thème', 'nuit')");
            $db->transaction(static fn () => $db->run("INSERT INTO parametres VALUES ('autre', 'x')"));
            throw new RuntimeException('échec');
        }));
        $this->assertSame(0, $db->value('SELECT count(*) FROM parametres'));
        $this->assertSame('ok', $db->transaction(static fn (): string => 'ok'));
    }

    public function testSauvegardesLimiteesEtUneParJour(): void
    {
        $tmp = new TempDir();
        mkdir($tmp->path . '/sauvegardes');
        $db = new Database($tmp->path . '/banque.sqlite');
        $db->migrate(dirname(__DIR__) . '/schema');
        $db->run("INSERT INTO parametres VALUES ('thème', 'nuit')");

        $file = $db->backup($tmp->path . '/sauvegardes');
        $copy = new Database($file);
        $this->assertSame('nuit', $copy->value("SELECT valeur FROM parametres WHERE cle = 'thème'"), 'sauvegarde complète');

        for ($i = 0; $i < 4; $i++) {
            $db->backup($tmp->path . '/sauvegardes', 3);
        }
        $this->assertSame(3, count(glob($tmp->path . '/sauvegardes/*.sqlite') ?: []));
        $this->assertSame(null, $db->backupOncePerDay($tmp->path . '/sauvegardes'), 'déjà une sauvegarde aujourd\'hui');
    }

    public function testEmplacements(): void
    {
        $paths = new Paths('/srv/banque/base/banque.sqlite', '/srv/banque/log', '/srv/banque/data');
        $this->assertSame('/srv/banque/base/sauvegardes', $paths->backupsDir());
        $this->assertSame('/srv/banque/base/archives', $paths->archivesDir());
        $this->assertSame('/srv/banque/log/connexions.log', $paths->connectionLog());

        $tmp = new TempDir();
        $paths = new Paths($tmp->path . '/base/banque.sqlite', $tmp->path . '/log', $tmp->path . '/data');
        $this->assertTrue(!$paths->migrationPending(), 'rien à migrer');
        mkdir($tmp->path . '/data');
        touch($tmp->path . '/data/categories.json');
        $this->assertTrue($paths->migrationPending(), 'anciens fichiers sans base');
        $paths->ensureDirectories();
        touch($paths->database);
        $this->assertTrue(!$paths->migrationPending(), 'base créée');
        $this->assertTrue(!(new Paths($tmp->path . '/autre.sqlite', $tmp->path . '/log', null))->migrationPending(), 'base d\'essai : data/ ignoré');
        $this->assertTrue(is_dir($tmp->path . '/log') && is_dir($tmp->path . '/base/sauvegardes'));
    }
}
