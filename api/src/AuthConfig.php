<?php

declare(strict_types=1);

namespace Banque;

/**
 * Réglages de la connexion qui dépendent de l'environnement.
 *
 * « Poste local » = les trois conditions à la fois : hôte localhost / 127.0.0.1 / [::1], pas de HTTPS, et client
 * sur la boucle locale (REMOTE_ADDR). Sur le VPS, Caddy sert le domaine en HTTPS et transmet l'adresse réelle du
 * client : la requête n'est jamais « locale ».
 *
 * La double authentification n'est désactivée que si BANQUE_TOTP=desactive ET la requête vient du poste local.
 * Sur le domaine, la variable est ignorée : une erreur de configuration ne peut pas retirer le TOTP en ligne.
 */
final class AuthConfig
{
    public const TOTP_DISABLED = 'desactive';
    private const LOOPBACK = ['127.0.0.1', '::1'];

    public function __construct(
        private readonly bool $totpDisabledRequested,
        private readonly bool $local,
    ) {
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param string|false $totpVariable valeur de BANQUE_TOTP (false si absente)
     */
    public static function fromServer(array $server, string|false $totpVariable): self
    {
        $host = is_string($server['HTTP_HOST'] ?? null) ? $server['HTTP_HOST'] : '';
        $https = is_string($server['HTTPS'] ?? null) && $server['HTTPS'] !== '' && strtolower($server['HTTPS']) !== 'off';
        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        $local = RequestGuard::isLocalHost($host) && !$https && in_array($remote, self::LOOPBACK, true);

        return new self(is_string($totpVariable) && strtolower(trim($totpVariable)) === self::TOTP_DISABLED, $local);
    }

    public function totpRequired(): bool
    {
        return !($this->totpDisabledRequested && $this->local);
    }

    /**
     * Vrai sur le poste local : sans HTTPS, le cookie ne peut être ni « Secure » ni préfixé « __Host- ».
     */
    public function local(): bool
    {
        return $this->local;
    }
}
