<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\EnvFile;
use RuntimeException;

final class EnvFileTest extends TestCase
{
    public function testLitLesVariablesEtIgnoreCommentairesEtLignesVides(): void
    {
        $content = "# Poste local\n\nBANQUE_TOTP=desactive\r\nexport BANQUE_BASE = \"/tmp/banque essai\"\nBANQUE_CLE_FICHIER='/home/moi/cle'\nBANQUE_HOTES=\n";
        $this->assertSame([
            'BANQUE_TOTP' => 'desactive',
            'BANQUE_BASE' => '/tmp/banque essai',
            'BANQUE_CLE_FICHIER' => '/home/moi/cle',
            'BANQUE_HOTES' => '',
        ], EnvFile::parse($content));
    }

    public function testRefuseLesLignesMalFormeesEtLesAutresVariables(): void
    {
        $this->assertThrows(RuntimeException::class, static fn () => EnvFile::parse("BANQUE_TOTP\n"));
        $this->assertThrows(RuntimeException::class, static fn () => EnvFile::parse("PATH=/tmp\n"));
        $this->assertThrows(RuntimeException::class, static fn () => EnvFile::parse("1BANQUE=x\n"));
    }

    public function testChargeSansEcraserLeShell(): void
    {
        $tmp = new TempDir();
        $suffix = strtoupper(bin2hex(random_bytes(3)));
        $fromFile = 'BANQUE_TEST_FICHIER_' . $suffix;
        $fromShell = 'BANQUE_TEST_SHELL_' . $suffix;
        putenv($fromShell . '=shell');
        file_put_contents($tmp->path . '/.env.local', "$fromFile=fichier\n$fromShell=fichier\n");
        try {
            $this->assertSame([$fromFile], EnvFile::load($tmp->path . '/.env.local'));
            $this->assertSame('fichier', getenv($fromFile));
            $this->assertSame('shell', getenv($fromShell), 'variable du shell conservée');
            $this->assertSame([], EnvFile::load($tmp->path . '/absent'), 'fichier absent : rien');
        } finally {
            putenv($fromFile);
            putenv($fromShell);
        }
    }
}
