<?php

declare(strict_types=1);

namespace Banque;

/**
 * Connexion à l'application, en deux temps :
 *
 * 1. identifiant + mot de passe → étape « code » (ou « connecte » si la double authentification est désactivée sur
 *    le poste local, voir AuthConfig) ;
 * 2. code TOTP ou code de secours → « connecte ».
 *
 * Une fois connecté : expiration après 30 min sans requête et au plus 12 h après la connexion ; jeton CSRF à
 * renvoyer dans l'en-tête X-CSRF-Token pour toute écriture. Les échecs passent par LoginThrottle et le journal.
 */
final class Auth
{
    public const IDLE_SECONDS = 1800;
    public const ABSOLUTE_SECONDS = 43200;
    /** Délai pour saisir le code après le mot de passe */
    public const PENDING_SECONDS = 300;
    public const STEP_CREDENTIALS = 'identifiants';
    public const STEP_CODE = 'code';
    public const STEP_CONNECTED = 'connecte';
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly SessionStore $session,
        private readonly Account $account,
        private readonly LoginThrottle $throttle,
        private readonly AuthConfig $config,
        private readonly AuditLog $log,
    ) {
    }

    /**
     * Réponse de GET /api/session : de quoi afficher la bonne étape de la page de connexion.
     *
     * @return array{compte: bool, totp: bool, etape: string, csrf: string|null, identifiant: string|null}
     */
    public function status(int $now): array
    {
        $step = $this->currentStep($now);
        $connected = $step === self::STEP_CONNECTED;

        return [
            'compte' => $this->account->exists(),
            'totp' => $this->config->totpRequired(),
            'etape' => $step,
            'csrf' => $connected ? $this->stringValue('csrf') : null,
            'identifiant' => $connected ? $this->stringValue('identifiant') : null,
        ];
    }

    /**
     * @return array<string, mixed> le nouvel état (voir status)
     * @throws HttpException 401 identifiants faux, 403 TOTP exigé mais non configuré, 429 trop de tentatives
     */
    public function loginWithPassword(string $username, #[\SensitiveParameter] string $password, string $ip, int $now): array
    {
        $this->throttle->check($ip, $now);
        if (!$this->account->verifyPassword($username, $password)) {
            $this->fail('mot_de_passe', $ip, $now);
            throw new HttpException(401, 'Identifiant ou mot de passe incorrect');
        }

        if (!$this->config->totpRequired()) {
            $this->complete($ip, $now, 'mot_de_passe');
            return $this->status($now);
        }
        if (!$this->account->hasTotp()) {
            $this->log->record('refus_sans_totp', $ip);
            throw new HttpException(403, 'Double authentification exigée mais non configurée : lancer php api/bin/compte.php totp-reset sur le serveur');
        }

        $this->session->regenerate();
        $this->session->set('etape', self::STEP_CODE);
        $this->session->set('code_depuis', $now);
        $this->session->set('identifiant', trim($username));

        return $this->status($now);
    }

    /**
     * @return array<string, mixed> le nouvel état (voir status)
     * @throws HttpException 401 étape mot de passe non faite, expirée ou code faux ; 429 trop de tentatives
     */
    public function loginWithCode(#[\SensitiveParameter] string $code, string $ip, int $now): array
    {
        if ($this->currentStep($now) !== self::STEP_CODE) {
            throw new HttpException(401, 'Session expirée : saisissez de nouveau votre mot de passe');
        }
        $this->throttle->check($ip, $now);
        $factor = $this->account->verifySecondFactor($code, $now);
        if ($factor === null) {
            $this->fail('code', $ip, $now);
            throw new HttpException(401, 'Code incorrect');
        }
        $this->complete($ip, $now, $factor);

        return $this->status($now) + ($factor === 'secours' ? ['codes_secours_restants' => $this->account->recoveryCodesLeft()] : []);
    }

    public function logout(string $ip, int $now): void
    {
        if ($this->currentStep($now) === self::STEP_CONNECTED) {
            $this->log->record('deconnexion', $ip);
        }
        $this->session->destroy();
    }

    /**
     * À appeler avant toute route protégée : prolonge la session.
     *
     * @throws HttpException 401 si l'utilisateur n'est pas connecté ou si la session a expiré
     */
    public function requireUser(int $now): void
    {
        if ($this->currentStep($now) !== self::STEP_CONNECTED) {
            throw new HttpException(401, 'Connexion requise');
        }
        $this->session->set('activite', $now);
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @throws HttpException 403 si une écriture n'envoie pas le bon jeton dans X-CSRF-Token
     */
    public function checkCsrf(array $server): void
    {
        $method = strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET');
        if (in_array($method, self::SAFE_METHODS, true)) {
            return;
        }
        $expected = $this->stringValue('csrf');
        $sent = $server['HTTP_X_CSRF_TOKEN'] ?? null;
        if ($expected === null || !is_string($sent) || !hash_equals($expected, $sent)) {
            throw new HttpException(403, 'Jeton CSRF absent ou invalide : rechargez la page');
        }
    }

    private function complete(string $ip, int $now, string $factor): void
    {
        $this->session->regenerate();
        $this->session->set('etape', self::STEP_CONNECTED);
        $this->session->set('identifiant', $this->account->username());
        $this->session->set('debut', $now);
        $this->session->set('activite', $now);
        $this->session->set('code_depuis', null);
        $this->session->set('csrf', bin2hex(random_bytes(32)));
        $this->throttle->recordSuccess($ip, $now);
        $this->log->record('connexion', $ip, ['facteur' => $factor]);
    }

    private function fail(string $step, string $ip, int $now): void
    {
        $locked = $this->throttle->recordFailure($ip, $now);
        $this->log->record('echec', $ip, ['etape' => $step]);
        if ($locked) {
            $this->log->record('verrouillage', $ip);
        }
    }

    /**
     * Étape actuelle, après application des expirations (une session expirée est détruite).
     */
    private function currentStep(int $now): string
    {
        $step = $this->session->get('etape');
        $expired = match ($step) {
            self::STEP_CONNECTED => $now - (int) $this->session->get('debut') >= self::ABSOLUTE_SECONDS
                || $now - (int) $this->session->get('activite') >= self::IDLE_SECONDS,
            self::STEP_CODE => $now - (int) $this->session->get('code_depuis') >= self::PENDING_SECONDS,
            default => false,
        };
        if ($expired) {
            $this->session->destroy();
            return self::STEP_CREDENTIALS;
        }

        return is_string($step) && in_array($step, [self::STEP_CODE, self::STEP_CONNECTED], true) ? $step : self::STEP_CREDENTIALS;
    }

    private function stringValue(string $key): ?string
    {
        $value = $this->session->get($key);

        return is_string($value) ? $value : null;
    }
}
