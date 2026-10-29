<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

/**
 * Small prefix-aware router for the BFF JSON API.
 */
final class Router
{
    /**
     * @var array<int, array{method: string, pattern: string, regex: string, handler: callable}>
     */
    private array $routes = [];

    private readonly string $prefix;

    public function __construct(string $prefix = '/de-bff/emergency')
    {
        $this->prefix = '/' . trim($prefix, '/');
    }

    public function add(string $method, string $pattern, callable $handler): self
    {
        $pattern = '/' . trim($pattern, '/');
        $pattern = $pattern === '/' ? '' : $pattern;
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn (array $matches): string => '(?P<'.$matches[1].'>[^/]+)',
            $pattern,
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^'.$regex.'$#',
            'handler' => $handler,
        ];

        return $this;
    }

    /**
     * @return array{handler: callable, params: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $relative = $this->relativePath($path);
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $relative, $matches) === 1) {
                $params = array_filter($matches, static fn (string|int $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);

                return ['handler' => $route['handler'], 'params' => $params];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function allowedMethods(string $path): array
    {
        $relative = $this->relativePath($path);
        $methods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $relative) === 1) {
                $methods[] = $route['method'];
            }
        }

        return array_values(array_unique($methods));
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    private function relativePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        if ($path === $this->prefix) {
            return '';
        }

        if (str_starts_with($path, $this->prefix.'/')) {
            $path = substr($path, strlen($this->prefix));
        }

        return rtrim($path, '/') ?: '';
    }
}
