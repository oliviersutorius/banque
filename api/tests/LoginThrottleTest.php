<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\HttpException;
use Banque\LoginThrottle;

final class LoginThrottleTest extends TestCase
{
    private const NOW = 1_700_000_000;

    private readonly LoginThrottle $throttle;

    public function __construct()
    {
        $this->throttle = new LoginThrottle(self::memoryDatabase());
    }

    private function assertLocked(string $ip, int $now, string $message = ''): void
    {
        try {
            $this->throttle->check($ip, $now);
        } catch (HttpException $e) {
            $this->assertSame(429, $e->status(), $message);
            return;
        }
        $this->assertTrue(false, $message !== '' ? $message : 'verrouillage attendu');
    }

    public function testVerrouilleQuinzeMinutesApresCinqEchecs(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->assertTrue(!$this->throttle->recordFailure('203.0.113.5', self::NOW + $i));
            $this->throttle->check('203.0.113.5', self::NOW + $i);
        }
        $this->assertTrue($this->throttle->recordFailure('203.0.113.5', self::NOW + 5), 'verrouillage signalé');
        $this->assertLocked('203.0.113.5', self::NOW + 6);
        $this->assertLocked('203.0.113.5', self::NOW + 5 + 899);
        $this->throttle->check('203.0.113.5', self::NOW + 5 + 900);
        $this->assertTrue(true);
    }

    public function testLeVerrouillageDoubleEnsuite(): void
    {
        $now = self::NOW;
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->recordFailure('203.0.113.5', $now);
        }
        $now += 900;
        for ($i = 0; $i < 5; $i++) {
            $this->throttle->recordFailure('203.0.113.5', $now);
        }
        $this->assertLocked('203.0.113.5', $now + 1799, '30 min au 2e verrouillage');
        $this->throttle->check('203.0.113.5', $now + 1800);
        $this->assertTrue(true);
    }

    public function testLeCompteEstVerrouilleMemeDepuisDesAdressesDifferentes(): void
    {
        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4', '198.51.100.5'] as $ip) {
            $this->throttle->recordFailure($ip, self::NOW);
        }
        $this->assertLocked('192.0.2.99', self::NOW + 1, 'nouvelle IP bloquée aussi');
    }

    public function testUneConnexionReussieRemetAZero(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->throttle->recordFailure('203.0.113.5', self::NOW);
        }
        $this->throttle->recordSuccess('203.0.113.5', self::NOW);
        $this->assertTrue(!$this->throttle->recordFailure('203.0.113.5', self::NOW), 'compteur repart de zéro');
        $this->throttle->check('203.0.113.5', self::NOW);
        $this->assertTrue(true);
    }

    public function testOublieLesVieuxEchecsEtDebloque(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->throttle->recordFailure('203.0.113.5', self::NOW);
        }
        $this->assertTrue(!$this->throttle->recordFailure('203.0.113.5', self::NOW + 86401), 'échecs de plus de 24 h oubliés');

        for ($i = 0; $i < 5; $i++) {
            $this->throttle->recordFailure('198.51.100.7', self::NOW + 90000);
        }
        $this->assertLocked('198.51.100.7', self::NOW + 90001);
        $this->throttle->reset();
        $this->throttle->check('198.51.100.7', self::NOW + 90001);
    }
}
