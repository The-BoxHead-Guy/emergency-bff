<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http\Middleware;

use DataEmergencia\EmergencyBff\Http\HttpError;
use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Http\Response;

/**
 * Rejects cross-origin browser calls and echoes the same-origin CORS headers.
 */
final class SameOriginMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $origin = $request->header('origin');

        if ($origin !== null && $origin !== '') {
            if (!$this->isSameOrigin($origin, $request->header('host'))) {
                throw new HttpError(403, 'cross_origin_denied', 'Cross-origin requests are not allowed.');
            }

            $response = $next($request);

            return $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Vary', 'Origin');
        }

        return $next($request);
    }

    private function isSameOrigin(string $origin, ?string $host): bool
    {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);

        if (!is_string($originHost) || $originHost === '' || $host === null) {
            return false;
        }

        $hostWithoutPort = strtok($host, ':');
        $requestPort = null;

        if (str_contains($host, ':')) {
            $requestPort = (int) substr($host, strrpos($host, ':') + 1);
        }

        if (strcasecmp($originHost, (string) $hostWithoutPort) !== 0) {
            return false;
        }

        if ($originPort === null || $requestPort === null) {
            return true;
        }

        return (int) $originPort === $requestPort;
    }
}
