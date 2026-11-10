<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\JsonFile;
use RuntimeException;

final class JsonFileTest extends TestCase
{
    private string $dir;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/banque-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/sauvegardes', 0700, true);
    }

    public function __destruct()
    {
        foreach (glob($this->dir . '/{,sauvegardes/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->dir . '/sauvegardes');
        @rmdir($this->dir);
    }

    public function testEcritEnUtf8LisibleEtRelit(): void
    {
        $json = new JsonFile($this->dir . '/sauvegardes');
        $path = $this->dir . '/categories.json';

        $json->write($path, ['dépenses' => [['nom' => 'Électricité & gaz', 'icone' => '🏠']], 'url' => 'a/b']);

        $content = (string) file_get_contents($path);
        $this->assertTrue(str_contains($content, '"dépenses"'), 'accents non échappés');
        $this->assertTrue(str_contains($content, '🏠'), 'emoji non échappé');
        $this->assertTrue(str_contains($content, '"a/b"'), 'barres obliques non échappées');
        $this->assertTrue(str_contains($content, "\n    "), 'JSON indenté');
        $this->assertSame('Électricité & gaz', $json->read($path)['dépenses'][0]['nom']);
    }

    public function testSauvegardeAvantModificationEtEnGarde20(): void
    {
        $json = new JsonFile($this->dir . '/sauvegardes');
        $path = $this->dir . '/202601.json';

        $json->write($path, ['version' => 0]);
        $this->assertSame([], glob($this->dir . '/sauvegardes/*') ?: [], 'pas de sauvegarde à la création');

        for ($i = 1; $i <= 25; $i++) {
            $json->write($path, ['version' => $i]);
        }

        $backups = glob($this->dir . '/sauvegardes/202601-*.json') ?: [];
        sort($backups);
        $this->assertSame(20, count($backups));
        $this->assertSame(['version' => 24], $json->read((string) end($backups)), 'la dernière sauvegarde est la version précédente');
        $this->assertSame(['version' => 25], $json->read($path));
        $this->assertSame([], glob($this->dir . '/*.tmp') ?: [], 'aucun fichier temporaire restant');
    }

    public function testInitialiseSansEcraser(): void
    {
        $json = new JsonFile($this->dir . '/sauvegardes');
        $path = $this->dir . '/regles.json';

        $json->initialize($path, [['motif' => 'A']]);
        $json->initialize($path, [['motif' => 'B']]);

        $this->assertSame([['motif' => 'A']], $json->read($path));
    }

    public function testJsonInvalideSignale(): void
    {
        $path = $this->dir . '/casse.json';
        file_put_contents($path, '{"oups":');

        $this->assertThrows(RuntimeException::class, fn () => (new JsonFile($this->dir . '/sauvegardes'))->read($path));
    }
}
