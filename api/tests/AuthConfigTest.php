<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\AuthConfig;

final class AuthConfigTest extends TestCase
{
    private const LOCAL = ['HTTP_HOST' => 'localhost:8000', 'REMOTE_ADDR' => '127.0.0.1'];
    private const ONLINE = ['HTTP_HOST' => 'comptes.example.fr', 'REMOTE_ADDR' => '203.0.113.5', 'HTTPS' => 'on'];

    public function testTotpExigePartoutParDefaut(): void
    {
        foreach ([self::LOCAL, self::ONLINE] as $server) {
            $this->assertTrue(AuthConfig::fromServer($server, false)->totpRequired());
            $this->assertTrue(AuthConfig::fromServer($server, 'obligatoire')->totpRequired());
            $this->assertTrue(AuthConfig::fromServer($server, 'n_importe_quoi')->totpRequired());
        }
    }

    public function testTotpDesactivableSurLePosteLocal(): void
    {
        $this->assertTrue(!AuthConfig::fromServer(self::LOCAL, 'desactive')->totpRequired());
        $this->assertTrue(!AuthConfig::fromServer(self::LOCAL, ' DESACTIVE ')->totpRequired());
        $this->assertTrue(!AuthConfig::fromServer(['HTTP_HOST' => '[::1]:5173', 'REMOTE_ADDR' => '::1'], 'desactive')->totpRequired());
    }

    public function testTotpToujoursExigeEnLigneMemeSiLaVariableLeDesactive(): void
    {
        $this->assertTrue(AuthConfig::fromServer(self::ONLINE, 'desactive')->totpRequired(), 'domaine en HTTPS');
        $cases = [
            'hôte local mais HTTPS' => ['HTTPS' => 'on'] + self::LOCAL,
            'hôte local mais client distant' => ['REMOTE_ADDR' => '203.0.113.5'] + self::LOCAL,
            'client local mais autre hôte' => ['HTTP_HOST' => 'comptes.example.fr'] + self::LOCAL,
            'adresse absente' => ['HTTP_HOST' => 'localhost:8000'],
        ];
        foreach ($cases as $label => $server) {
            $this->assertTrue(AuthConfig::fromServer($server, 'desactive')->totpRequired(), $label);
        }
        $this->assertTrue(!AuthConfig::fromServer(['HTTPS' => 'off'] + self::LOCAL, 'desactive')->totpRequired(), 'HTTPS=off');
    }

    public function testModeLocalPourLeCookie(): void
    {
        $this->assertTrue(AuthConfig::fromServer(self::LOCAL, false)->local());
        $this->assertTrue(!AuthConfig::fromServer(self::ONLINE, false)->local());
    }
}
