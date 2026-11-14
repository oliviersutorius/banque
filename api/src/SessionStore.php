<?php

declare(strict_types=1);

namespace Banque;

/**
 * Données de session de l'utilisateur. Implémentée par PhpSession (sessions PHP natives) et, dans les tests, par
 * un simple tableau en mémoire.
 */
interface SessionStore
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    /**
     * Nouvel identifiant de session, en gardant les données (contre la fixation de session).
     */
    public function regenerate(): void;

    /**
     * Efface les données, l'identifiant et le cookie.
     */
    public function destroy(): void;
}
