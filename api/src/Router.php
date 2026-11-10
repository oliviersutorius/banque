<?php

declare(strict_types=1);

namespace Banque;

/**
 * Routeur minimal : associe une méthode HTTP et un motif d'URL à un gestionnaire.
 *
 * Les segments dynamiques s'écrivent {nom:regex}, par exemple /api/mois/{mois:\d{6}}.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace_callback(
            '/\{(\w+):([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>' . $m[2] . ')',
            $pattern,
        );
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    /**
     * @return mixed la valeur renvoyée par le gestionnaire, sérialisée en JSON par l'appelant
     */
    public function dispatch(string $method, string $path): mixed
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== strtoupper($method)) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            return ($route['handler'])($params);
        }

        if ($allowed !== []) {
            throw new HttpException(405, 'Méthode non autorisée', ['autorisées' => array_values(array_unique($allowed))]);
        }
        throw new HttpException(404, 'Route inconnue : ' . $path);
    }
}
