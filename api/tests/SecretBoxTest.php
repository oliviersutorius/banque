<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\SecretBox;
use RuntimeException;

final class SecretBoxTest extends TestCase
{
    public function testChiffreEtDechiffre(): void
    {
        $box = SecretBox::fromBase64(SecretBox::generateKey());
        $secret = random_bytes(20);
        $encrypted = $box->encrypt($secret);
        $this->assertTrue(!str_contains((string) base64_decode($encrypted), $secret), 'texte clair absent');
        $this->assertTrue($encrypted !== $box->encrypt($secret), 'nonce aléatoire');
        $this->assertSame($secret, $box->decrypt($encrypted));
    }

    public function testRefuseUneAutreCleOuUnMessageModifie(): void
    {
        $box = SecretBox::fromBase64(SecretBox::generateKey());
        $encrypted = $box->encrypt('secret');
        $other = SecretBox::fromBase64(SecretBox::generateKey());
        $this->assertThrows(RuntimeException::class, static fn () => $other->decrypt($encrypted));

        $raw = (string) base64_decode($encrypted);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->assertThrows(RuntimeException::class, static fn () => $box->decrypt(base64_encode($raw)));
        $this->assertThrows(RuntimeException::class, static fn () => $box->decrypt('pas du base64 !'));
    }

    public function testCleInvalide(): void
    {
        $this->assertThrows(RuntimeException::class, static fn () => SecretBox::fromBase64(base64_encode('trop courte')));
        $this->assertThrows(RuntimeException::class, static fn () => SecretBox::fromBase64('***'));
    }

    public function testSourcesDeLaCle(): void
    {
        $tmp = new TempDir();
        $key = SecretBox::generateKey();
        file_put_contents($tmp->path . '/cle', $key . "\n");

        $this->assertSame(null, SecretBox::fromSources(false, false));
        $this->assertSame(null, SecretBox::fromSources('', ''));
        $fromVariable = SecretBox::fromSources($key, false);
        $fromFile = SecretBox::fromSources(false, $tmp->path . '/cle');
        $this->assertTrue($fromVariable !== null && $fromFile !== null);
        $this->assertSame('x', $fromFile->decrypt($fromVariable->encrypt('x')), 'même clé, retour à la ligne ignoré');
        $this->assertThrows(RuntimeException::class, static fn () => SecretBox::fromSources(false, $tmp->path . '/absente'));
    }
}
