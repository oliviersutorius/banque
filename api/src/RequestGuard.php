<?php

declare(strict_types=1);

namespace Banque;

/**
 * Contrôles faits avant toute route de l'API, à partir des en-têtes de la requête ($_SERVER).
 *
 * - Hôte : seuls localhost, 127.0.0.1 et [::1] (plus les noms de BANQUE_HOTES) sont servis, quel que soit le port.
 *   Une page dont le nom de domaine est réaffecté vers 127.0.0.1 (DNS rebinding) ne peut donc pas lire l'API.
 * - Origine : une requête qui modifie des données doit venir de la même origine (Origin = hôte de la requête,
 *   Sec-Fetch-Site différent de « cross-site » et « same-site »). Bloque les requêtes envoyées par un autre site (CSRF).
 * - Type du corps : un corps JSON doit être annoncé en application/json, ce qu'un autre site ne peut pas faire sans
 *   pré-vérification CORS (refusée, l'API n'envoie aucun en-tête CORS).
 */
final class RequestGuard
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var list<string> */
    private readonly array $hosts;

    /**
     * @param list<string> $extraHosts noms d'hôte autorisés en plus des adresses locales (sans port)
     */
    public function __construct(array $extraHosts = [])
    {
        $this->hosts = [...self::LOCAL_HOSTS, ...array_map('strtolower', $extraHosts)];
    }

    /**
     * @param string $list noms séparés par des virgules, par exemple la variable d'environnement BANQUE_HOTES
     */
    public static function fromList(string $list): self
    {
        return new self(array_values(array_filter(array_map('trim', explode(',', $list)), static fn (string $h): bool => $h !== '')));
    }

    /**
     * @param array<string, mixed> $server
     * @throws HttpException 403 si l'hôte ou l'origine n'est pas autorisé
     */
    public function check(array $server): void
    {
        $host = is_string($server['HTTP_HOST'] ?? null) ? strtolower($server['HTTP_HOST']) : '';
        if (!in_array(self::hostname($host), $this->hosts, true)) {
            throw new HttpException(403, 'Hôte non autorisé');
        }

        $method = strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET');
        if (in_array($method, self::SAFE_METHODS, true)) {
            return;
        }
        $fetchSite = $server['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($fetchSite === 'cross-site' || $fetchSite === 'same-site') {
            throw new HttpException(403, 'Origine non autorisée');
        }
        $origin = $server['HTTP_ORIGIN'] ?? null;
        if (is_string($origin) && strtolower(self::authority($origin)) !== $host) {
            throw new HttpException(403, 'Origine non autorisée');
        }
    }

    /**
     * @param array<string, mixed> $server
     * @throws HttpException 415 si le corps n'est pas annoncé en application/json
     */
    public static function requireJson(array $server): void
    {
        $type = is_string($server['CONTENT_TYPE'] ?? null) ? $server['CONTENT_TYPE'] : '';
        if (strtolower(trim(explode(';', $type)[0])) !== 'application/json') {
            throw new HttpException(415, 'Corps JSON attendu (Content-Type: application/json)');
        }
    }

    /**
     * « localhost:8000 » → « localhost », « [::1]:8000 » → « [::1] »
     */
    private static function hostname(string $host): string
    {
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            return $end === false ? '' : substr($host, 0, $end + 1);
        }

        return explode(':', $host)[0];
    }

    /**
     * « http://localhost:5173 » → « localhost:5173 » ; « null » ou une origine invalide → chaîne vide
     */
    private static function authority(string $origin): string
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }
        $host = str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[') ? '[' . $parts['host'] . ']' : $parts['host'];

        return $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
