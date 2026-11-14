<?php

declare(strict_types=1);

namespace Banque;

use RuntimeException;

/**
 * Session PHP native, durcie :
 *
 * - cookie « __Host-banque_session », Secure, HttpOnly, SameSite=Strict, sans durée (supprimé à la fermeture du
 *   navigateur) ; sur le poste local en HTTP, « banque_session » sans Secure (le préfixe __Host- l'exige) ;
 * - mode strict (un identifiant inconnu envoyé par le navigateur est remplacé), cookies uniquement ;
 * - fichiers de session dans data/sessions/ (dossier en 0700) ;
 * - la session n'est démarrée que si le navigateur envoie déjà un cookie, ou au moment de la connexion : les
 *   requêtes anonymes ne créent pas de fichier.
 */
final class PhpSession implements SessionStore
{
    public const NAME = '__Host-banque_session';
    public const LOCAL_NAME = 'banque_session';

    public function __construct(
        private readonly string $saveDir,
        private readonly bool $local,
        private readonly int $maxLifetime,
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->start(false) ? ($_SESSION[$key] ?? null) : null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start(true);
        $_SESSION[$key] = $value;
    }

    public function regenerate(): void
    {
        $this->start(true);
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        if (!$this->start(false)) {
            return;
        }
        $_SESSION = [];
        setcookie($this->name(), '', ['expires' => 1] + $this->cookieParams());
        session_destroy();
    }

    private function name(): string
    {
        return $this->local ? self::LOCAL_NAME : self::NAME;
    }

    /**
     * @return array{path: string, secure: bool, httponly: bool, samesite: string}
     */
    private function cookieParams(): array
    {
        return ['path' => '/', 'secure' => !$this->local, 'httponly' => true, 'samesite' => 'Strict'];
    }

    /**
     * @param bool $create démarrer même si le navigateur n'a pas encore de cookie de session
     * @return bool vrai si la session est active
     */
    private function start(bool $create): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (!$create && !isset($_COOKIE[$this->name()])) {
            return false;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) $this->maxLifetime);
        session_save_path($this->saveDir);
        session_name($this->name());
        session_set_cookie_params(['lifetime' => 0] + $this->cookieParams());
        if (!session_start()) {
            throw new RuntimeException('Démarrage de la session impossible');
        }

        return true;
    }
}
