<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\Account;
use Banque\Database;
use Banque\SecretBox;
use Banque\Totp;
use InvalidArgumentException;
use RuntimeException;

final class AccountTest extends TestCase
{
    /** Coût réduit : les tests n'ont pas besoin de la résistance d'Argon2id */
    public const FAST_HASH = ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1];
    private const NOW = 1_700_000_000;

    private readonly Database $db;
    private readonly SecretBox $box;

    public function __construct()
    {
        $this->db = self::memoryDatabase();
        $this->box = SecretBox::fromBase64(SecretBox::generateKey());
    }

    private function account(?SecretBox $box = null): Account
    {
        return new Account($this->db, $box ?? $this->box, self::FAST_HASH);
    }

    /**
     * Tout le contenu des tables du compte, pour vérifier qu'aucun secret n'y est en clair.
     */
    private function stored(): string
    {
        return (string) json_encode([$this->db->all('SELECT * FROM compte'), $this->db->all('SELECT * FROM codes_secours')]);
    }

    public function testCreeUnCompteSansStockerDeSecretEnClair(): void
    {
        $account = $this->account();
        $this->assertTrue(!$account->exists());
        $created = $account->create(' olivier ', 'un mot de passe long', true);

        $content = $this->stored();
        $this->assertTrue(!str_contains($content, 'un mot de passe long'), 'mot de passe absent');
        $this->assertTrue(str_contains($content, '$argon2id$'), 'empreinte Argon2id');
        $this->assertTrue(!str_contains($content, Totp::base32Encode((string) $created['secret'])), 'secret TOTP chiffré');
        $this->assertSame(10, count($created['codes']));
        foreach ($created['codes'] as $code) {
            $this->assertTrue(preg_match('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $code) === 1, $code);
            $this->assertTrue(!str_contains($content, $code), 'code de secours absent en clair');
        }
        $this->assertSame('olivier', $account->username());
        $this->assertTrue($account->hasTotp());
    }

    public function testMotDePasseTropCourtOuIdentifiantVide(): void
    {
        $account = $this->account();
        $this->assertThrows(InvalidArgumentException::class, static fn () => $account->create('olivier', 'court', true));
        $this->assertThrows(InvalidArgumentException::class, static fn () => $account->create('  ', 'un mot de passe long', true));
        $this->assertThrows(InvalidArgumentException::class, static fn () => Account::validatePassword('éééééééééé1'));
        Account::validatePassword('ééééééééééé1');
        $this->assertTrue(!$account->exists());
    }

    public function testVerifieIdentifiantEtMotDePasse(): void
    {
        $account = $this->account();
        $this->assertTrue(!$account->verifyPassword('olivier', 'un mot de passe long'), 'aucun compte');
        $account->create('olivier', 'un mot de passe long', false);

        $this->assertTrue($account->verifyPassword('olivier', 'un mot de passe long'));
        $this->assertTrue($account->verifyPassword(' olivier', 'un mot de passe long'), 'espaces autour de l\'identifiant');
        $this->assertTrue(!$account->verifyPassword('olivier', 'un mot de passe LONG'));
        $this->assertTrue(!$account->verifyPassword('Olivier', 'un mot de passe long'), 'identifiant sensible à la casse');
        $this->assertTrue(!$account->verifyPassword('', ''));
    }

    public function testRecalculeUneEmpreinteAuxAnciensParametres(): void
    {
        $this->account()->create('olivier', 'un mot de passe long', false);
        $stronger = new Account($this->db, $this->box, ['memory_cost' => 2048, 'time_cost' => 1, 'threads' => 1]);
        $this->assertTrue($stronger->verifyPassword('olivier', 'un mot de passe long'));
        $hash = (string) $this->db->value('SELECT mot_de_passe FROM compte');
        $this->assertTrue(str_contains($hash, 'm=2048'), 'empreinte recalculée : ' . $hash);
    }

    public function testCompteLocalSansTotp(): void
    {
        $account = $this->account();
        $created = $account->create('olivier', 'un mot de passe long', false);
        $this->assertSame(null, $created['secret']);
        $this->assertSame([], $created['codes']);
        $this->assertTrue(!$account->hasTotp());
        $this->assertSame(null, $account->verifySecondFactor('123456', self::NOW));

        // Sans TOTP, aucune clé n'est nécessaire
        $withoutKey = new Account(self::memoryDatabase(), null, self::FAST_HASH);
        $withoutKey->create('olivier', 'un mot de passe long', false);
        $this->assertThrows(RuntimeException::class, static fn () => $withoutKey->create('olivier', 'un mot de passe long', true));
    }

    public function testCodeTotpAccepteUneSeuleFois(): void
    {
        $account = $this->account();
        $secret = (string) $account->create('olivier', 'un mot de passe long', true)['secret'];
        $code = Totp::code($secret, Totp::step(self::NOW));

        $this->assertSame(null, $account->verifySecondFactor('000000' === $code ? '111111' : '000000', self::NOW));
        $this->assertSame('totp', $account->verifySecondFactor($code, self::NOW));
        $this->assertSame(null, $account->verifySecondFactor($code, self::NOW), 'rejeu refusé');
        $this->assertSame('totp', $account->verifySecondFactor(Totp::code($secret, Totp::step(self::NOW) + 1), self::NOW + 30));
    }

    public function testCodeDeSecoursConsomme(): void
    {
        $account = $this->account();
        $codes = $account->create('olivier', 'un mot de passe long', true)['codes'];

        $this->assertSame('secours', $account->verifySecondFactor(strtoupper(str_replace('-', ' ', $codes[3])), self::NOW), 'saisie tolérante');
        $this->assertSame(9, $account->recoveryCodesLeft());
        $this->assertSame(null, $account->verifySecondFactor($codes[3], self::NOW), 'déjà utilisé');
        $this->assertSame('secours', $account->verifySecondFactor($codes[0], self::NOW));
        $this->assertSame(8, $account->recoveryCodesLeft());
    }

    public function testMauvaiseCleDeChiffrement(): void
    {
        $this->account()->create('olivier', 'un mot de passe long', true);
        $other = $this->account(SecretBox::fromBase64(SecretBox::generateKey()));
        $this->assertThrows(RuntimeException::class, static fn () => $other->verifySecondFactor('123456', self::NOW));
    }

    public function testChangeLeMotDePasseEtLeTotp(): void
    {
        $account = $this->account();
        $account->create('olivier', 'un mot de passe long', false);
        $account->changePassword('un autre mot de passe');
        $this->assertTrue($account->verifyPassword('olivier', 'un autre mot de passe'));
        $this->assertTrue(!$account->verifyPassword('olivier', 'un mot de passe long'));
        $this->assertThrows(InvalidArgumentException::class, static fn () => $account->changePassword('court'));

        $reset = $account->resetSecondFactor();
        $this->assertTrue($account->hasTotp(), 'TOTP ajouté à un compte local');
        $this->assertSame(10, $account->recoveryCodesLeft());
        $this->assertSame('totp', $account->verifySecondFactor(Totp::code($reset['secret'], Totp::step(self::NOW)), self::NOW));
        $this->assertTrue($account->verifyPassword('olivier', 'un autre mot de passe'), 'mot de passe conservé');
    }

    public function testActionsSansCompte(): void
    {
        $account = $this->account();
        $this->assertThrows(RuntimeException::class, static fn () => $account->changePassword('un mot de passe long'));
        $this->assertThrows(RuntimeException::class, static fn () => $account->resetSecondFactor());
        $this->assertSame(null, $account->username());
        $this->assertTrue(!$account->hasTotp());
    }

    public function testRecreerOuChangerFermeLesSessions(): void
    {
        $account = $this->account();
        $account->create('olivier', 'un mot de passe long', true);
        $this->db->run("INSERT INTO sessions VALUES ('s1', 'x', 9999999999)");
        $account->closeSessions();
        $this->assertSame(0, $this->db->value('SELECT count(*) FROM sessions'));

        $this->db->run("INSERT INTO sessions VALUES ('s2', 'x', 9999999999)");
        $account->create('olivier', 'un autre mot de passe', false);
        $this->assertSame(0, $this->db->value('SELECT count(*) FROM sessions'), 'nouveau compte : sessions fermées');
        $this->assertSame(0, $account->recoveryCodesLeft(), 'anciens codes de secours supprimés');
        $this->assertSame(1, $this->db->value('SELECT count(*) FROM compte'));
    }
}
