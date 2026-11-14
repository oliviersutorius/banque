<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\Totp;
use InvalidArgumentException;

final class TotpTest extends TestCase
{
    private const RFC_SECRET = '12345678901234567890';

    public function testVecteursDeLaRfc6238EnHuitChiffres(): void
    {
        // RFC 6238, annexe B, HMAC-SHA1
        $vectors = [59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'];
        foreach ($vectors as $time => $expected) {
            $this->assertSame($expected, Totp::code(self::RFC_SECRET, Totp::step($time), 8), 'T = ' . $time);
        }
    }

    public function testCodeASixChiffresParDefaut(): void
    {
        $this->assertSame('287082', Totp::code(self::RFC_SECRET, Totp::step(59)));
        $this->assertSame('005924', Totp::code(self::RFC_SECRET, Totp::step(1234567890)), 'zéros de tête conservés');
    }

    public function testVerifieAvecUnPasDeToleranceDeChaqueCote(): void
    {
        $now = 1_700_000_000;
        $step = Totp::step($now);
        foreach ([-1, 0, 1] as $delta) {
            $this->assertSame($step + $delta, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $delta), $now, 0));
        }
        foreach ([-2, 2] as $delta) {
            $this->assertSame(null, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + $delta), $now, 0));
        }
    }

    public function testRefuseUnCodeDejaUtilise(): void
    {
        $now = 1_700_000_000;
        $code = Totp::code(self::RFC_SECRET, Totp::step($now));
        $used = Totp::verify(self::RFC_SECRET, $code, $now, 0);
        $this->assertSame(Totp::step($now), $used);
        $this->assertSame(null, Totp::verify(self::RFC_SECRET, $code, $now, (int) $used), 'rejeu refusé');
        $this->assertSame(null, Totp::verify(self::RFC_SECRET, Totp::code(self::RFC_SECRET, Totp::step($now) - 1), $now, (int) $used), 'pas plus ancien refusé');
    }

    public function testRefuseLesSaisiesMalFormees(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '12 34 5x'] as $code) {
            $this->assertSame(null, Totp::verify(self::RFC_SECRET, $code, 59, 0), $code);
        }
        $this->assertSame(Totp::step(59), Totp::verify(self::RFC_SECRET, '287 082', 59, 0), 'espaces tolérés');
    }

    public function testBase32DansLesDeuxSens(): void
    {
        // RFC 4648, §10
        $vectors = ['' => '', 'f' => 'MY', 'fo' => 'MZXQ', 'foo' => 'MZXW6', 'foob' => 'MZXW6YQ', 'fooba' => 'MZXW6YTB', 'foobar' => 'MZXW6YTBOI'];
        foreach ($vectors as $plain => $encoded) {
            $this->assertSame($encoded, Totp::base32Encode((string) $plain));
            $this->assertSame((string) $plain, Totp::base32Decode($encoded));
        }
        $this->assertSame('foobar', Totp::base32Decode('mzxw 6ytb oi======'));
        $secret = Totp::generateSecret();
        $this->assertSame(20, strlen($secret));
        $this->assertSame($secret, Totp::base32Decode(Totp::base32Encode($secret)));
        $this->assertThrows(InvalidArgumentException::class, static fn () => Totp::base32Decode('MZ1W'));
    }

    public function testUriPourLApplicationDAuthentification(): void
    {
        $this->assertSame(
            'otpauth://totp/Mes%20Comptes:olivier?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=Mes%20Comptes&algorithm=SHA1&digits=6&period=30',
            Totp::uri(self::RFC_SECRET, 'olivier', 'Mes Comptes'),
        );
    }
}
