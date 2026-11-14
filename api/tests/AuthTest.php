<?php

declare(strict_types=1);

namespace Banque\Tests;

use Banque\Account;
use Banque\AuditLog;
use Banque\Auth;
use Banque\AuthConfig;
use Banque\HttpException;
use Banque\Database;
use Banque\LoginThrottle;
use Banque\SecretBox;
use Banque\Totp;

final class AuthTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const IP = '203.0.113.5';
    private const PASSWORD = 'un mot de passe long';
    private const LOCAL = ['HTTP_HOST' => 'localhost:8000', 'REMOTE_ADDR' => '127.0.0.1'];
    private const ONLINE = ['HTTP_HOST' => 'comptes.example.fr', 'REMOTE_ADDR' => self::IP, 'HTTPS' => 'on'];

    private readonly TempDir $tmp;
    private readonly Database $db;
    private readonly Account $account;
    private readonly ArraySession $session;
    private string $secret = '';
    /** @var list<string> */
    private array $codes = [];

    public function __construct()
    {
        $this->tmp = new TempDir();
        $this->db = self::memoryDatabase();
        $this->account = new Account($this->db, SecretBox::fromBase64(SecretBox::generateKey()), AccountTest::FAST_HASH);
        $this->session = new ArraySession();
    }

    /**
     * @param array<string, string> $server
     */
    private function auth(array $server = self::ONLINE, string|false $totpVariable = false): Auth
    {
        return new Auth(
            $this->session,
            $this->account,
            new LoginThrottle($this->db),
            AuthConfig::fromServer($server, $totpVariable),
            new AuditLog($this->tmp->path . '/connexions.log'),
        );
    }

    private function createAccount(bool $withTotp = true): void
    {
        $created = $this->account->create('olivier', self::PASSWORD, $withTotp);
        $this->secret = (string) $created['secret'];
        $this->codes = $created['codes'];
    }

    private function code(int $now = self::NOW): string
    {
        return Totp::code($this->secret, Totp::step($now));
    }

    private function assertStatus(int $status, callable $fn, string $message = ''): void
    {
        try {
            $fn();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->status(), $message . ' (' . $e->getMessage() . ')');
            return;
        }
        $this->assertTrue(false, 'HttpException ' . $status . ' attendue ' . $message);
    }

    private function login(Auth $auth): void
    {
        $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, self::NOW);
        $auth->loginWithCode($this->code(), self::IP, self::NOW);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journal(): array
    {
        $lines = file($this->tmp->path . '/connexions.log', FILE_IGNORE_NEW_LINES) ?: [];

        return array_map(static fn (string $l): array => (array) json_decode($l, true), $lines);
    }

    public function testSansSessionLesRoutesRepondent401(): void
    {
        $this->createAccount();
        $auth = $this->auth();
        $this->assertStatus(401, static fn () => $auth->requireUser(self::NOW));
        $status = $auth->status(self::NOW);
        $this->assertSame(['compte' => true, 'totp' => true, 'etape' => 'identifiants', 'csrf' => null, 'identifiant' => null], $status);
    }

    public function testConnexionEnDeuxTemps(): void
    {
        $this->createAccount();
        $auth = $this->auth();

        $status = $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, self::NOW);
        $this->assertSame('code', $status['etape']);
        $this->assertSame(null, $status['csrf']);
        $this->assertStatus(401, static fn () => $auth->requireUser(self::NOW), 'mot de passe seul insuffisant');

        $status = $auth->loginWithCode($this->code(), self::IP, self::NOW + 10);
        $this->assertSame('connecte', $status['etape']);
        $this->assertSame('olivier', $status['identifiant']);
        $this->assertTrue(is_string($status['csrf']) && strlen($status['csrf']) === 64, 'jeton CSRF');
        $this->assertSame(2, $this->session->regenerations, 'nouvel identifiant de session à chaque étape');
        $auth->requireUser(self::NOW + 20);

        $this->assertSame(['connexion'], array_column(array_slice($this->journal(), -1), 'evenement'));
    }

    public function testMauvaisIdentifiantsMemeMessage(): void
    {
        $this->createAccount();
        $auth = $this->auth();
        $messages = [];
        foreach ([['olivier', 'mauvais mot de passe'], ['inconnu', self::PASSWORD]] as [$user, $password]) {
            try {
                $auth->loginWithPassword($user, $password, self::IP, self::NOW);
            } catch (HttpException $e) {
                $this->assertSame(401, $e->status());
                $messages[] = $e->getMessage();
            }
        }
        $this->assertSame(2, count($messages));
        $this->assertSame($messages[0], $messages[1], 'compte inconnu indiscernable');
        $this->assertSame('identifiants', $auth->status(self::NOW)['etape']);
        $journal = $this->journal();
        $this->assertSame(['echec', 'echec'], array_column($journal, 'evenement'));
        $this->assertTrue(!str_contains((string) file_get_contents($this->tmp->path . '/connexions.log'), 'mauvais'), 'aucun mot de passe journalisé');
    }

    public function testCodeFauxOuSansEtapeMotDePasse(): void
    {
        $this->createAccount();
        $auth = $this->auth();
        $code = $this->code();
        $this->assertStatus(401, static fn () => $auth->loginWithCode($code, self::IP, self::NOW), 'sans mot de passe');

        $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, self::NOW);
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->assertStatus(401, static fn () => $auth->loginWithCode($wrong, self::IP, self::NOW));
        $this->assertSame('code', $auth->status(self::NOW)['etape'], 'on peut réessayer');
        $auth->loginWithCode($code, self::IP, self::NOW);
        $this->assertSame('connecte', $auth->status(self::NOW)['etape']);
    }

    public function testCodeTotpReutiliseRefuse(): void
    {
        $this->createAccount();
        $auth = $this->auth();
        $this->login($auth);
        $auth->logout(self::IP, self::NOW + 5);

        $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, self::NOW + 6);
        $code = $this->code();
        $this->assertStatus(401, static fn () => $auth->loginWithCode($code, self::IP, self::NOW + 7), 'même code');
    }

    public function testCodeDeSecours(): void
    {
        $this->createAccount();
        $auth = $this->auth();
        $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, self::NOW);
        $status = $auth->loginWithCode($this->codes[0], self::IP, self::NOW);
        $this->assertSame('connecte', $status['etape']);
        $this->assertSame(9, $status['codes_secours_restants']);
        $this->assertSame('secours', $this->journal()[0]['facteur']);
    }

    public function testDelaiPourSaisirLeCode(): void
    {
        $this->createAccount();
        $auth = $this->auth();
        $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, self::NOW);
        $later = self::NOW + Auth::PENDING_SECONDS;
        $code = $this->code($later);
        $this->assertStatus(401, static fn () => $auth->loginWithCode($code, self::IP, $later));
        $this->assertSame('identifiants', $auth->status($later)['etape']);
    }

    public function testSixiemeEchecRepond429(): void
    {
        $this->createAccount();
        $auth = $this->auth();
        for ($i = 0; $i < 5; $i++) {
            $this->assertStatus(401, static fn () => $auth->loginWithPassword('olivier', 'mauvais', self::IP, self::NOW));
        }
        $this->assertStatus(429, static fn () => $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, self::NOW + 1), 'même le bon mot de passe');
        $this->assertTrue(in_array('verrouillage', array_column($this->journal(), 'evenement'), true));

        $later = self::NOW + 900;
        $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, $later);
        $this->assertSame('code', $auth->status($later)['etape'], 'débloqué après 15 min');
    }

    public function testLesCodesFauxComptentAussi(): void
    {
        $this->createAccount();
        $auth = $this->auth();
        $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, self::NOW);
        for ($i = 0; $i < 5; $i++) {
            $this->assertStatus(401, static fn () => $auth->loginWithCode('99999' . $i, self::IP, self::NOW));
        }
        $code = $this->code();
        $this->assertStatus(429, static fn () => $auth->loginWithCode($code, self::IP, self::NOW));
    }

    public function testExpirationParInactiviteEtAbsolue(): void
    {
        $this->createAccount();
        $auth = $this->auth();
        $this->login($auth);
        $auth->requireUser(self::NOW + Auth::IDLE_SECONDS - 1);
        $this->assertStatus(401, static fn () => $auth->requireUser(self::NOW + 2 * Auth::IDLE_SECONDS - 1), 'inactivité');
        $this->assertSame(1, $this->session->destructions);

        $this->session->data = [];
        $start = self::NOW + 100_000;
        $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, $start);
        $auth->loginWithCode($this->code($start), self::IP, $start);
        for ($t = $start; $t < $start + Auth::ABSOLUTE_SECONDS; $t += Auth::IDLE_SECONDS - 60) {
            $auth->requireUser($t);
        }
        $this->assertStatus(401, static fn () => $auth->requireUser($start + Auth::ABSOLUTE_SECONDS), 'durée maximale');
    }

    public function testJetonCsrfExigePourLesEcritures(): void
    {
        $this->createAccount();
        $auth = $this->auth();
        $this->login($auth);
        $token = (string) $auth->status(self::NOW)['csrf'];

        $auth->checkCsrf(['REQUEST_METHOD' => 'GET']);
        $auth->checkCsrf(['REQUEST_METHOD' => 'POST', 'HTTP_X_CSRF_TOKEN' => $token]);
        $this->assertStatus(403, static fn () => $auth->checkCsrf(['REQUEST_METHOD' => 'POST']), 'absent');
        $this->assertStatus(403, static fn () => $auth->checkCsrf(['REQUEST_METHOD' => 'DELETE', 'HTTP_X_CSRF_TOKEN' => str_repeat('0', 64)]), 'faux');
    }

    public function testDeconnexion(): void
    {
        $this->createAccount();
        $auth = $this->auth();
        $this->login($auth);
        $auth->logout(self::IP, self::NOW + 1);
        $this->assertStatus(401, static fn () => $auth->requireUser(self::NOW + 2));
        $this->assertSame('deconnexion', $this->journal()[1]['evenement']);
    }

    public function testEnLocalLeMotDePasseSuffitSiLeTotpEstDesactive(): void
    {
        $this->createAccount(false);
        $auth = $this->auth(self::LOCAL, 'desactive');
        $this->assertSame(false, $auth->status(self::NOW)['totp']);
        $status = $auth->loginWithPassword('olivier', self::PASSWORD, '127.0.0.1', self::NOW);
        $this->assertSame('connecte', $status['etape']);
        $auth->requireUser(self::NOW);
        $this->assertSame('mot_de_passe', $this->journal()[0]['facteur']);
    }

    public function testEnLigneLeCodeResteExigeMalgreLaVariable(): void
    {
        $this->createAccount();
        $auth = $this->auth(self::ONLINE, 'desactive');
        $this->assertSame(true, $auth->status(self::NOW)['totp']);
        $this->assertSame('code', $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, self::NOW)['etape']);
        $this->assertStatus(401, static fn () => $auth->requireUser(self::NOW));
    }

    public function testEnLigneUnCompteSansTotpEstRefuse(): void
    {
        $this->createAccount(false);
        $auth = $this->auth(self::ONLINE, 'desactive');
        $this->assertStatus(403, static fn () => $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, self::NOW));
        $this->assertStatus(401, static fn () => $auth->requireUser(self::NOW));
        $this->assertSame('refus_sans_totp', $this->journal()[0]['evenement']);
    }

    public function testAucunCompte(): void
    {
        $auth = $this->auth();
        $this->assertSame(false, $auth->status(self::NOW)['compte']);
        $this->assertStatus(401, static fn () => $auth->loginWithPassword('olivier', self::PASSWORD, self::IP, self::NOW));
    }
}
