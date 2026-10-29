<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http\Middleware;

use DataEmergencia\EmergencyBff\Http\HttpError;
use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Http\Response;
use DataEmergencia\EmergencyBff\Session\SessionManager;

/**
 * Validates the CSRF token on every mutating route.
 */
final class CsrfMiddleware implements Middleware
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly SessionManager $session)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (in_array(strtoupper($request->method), self::MUTATING_METHODS, true)) {
            $token = $request->header('x-csrf-token') ?? $request->input('csrf_token');
            $token = is_string($token) ? $token : null;

            if (!$this->session->validateCsrf($token)) {
                throw new HttpError(403, 'csrf_failed', 'The CSRF token is missing or invalid.');
            }
        }

        return $next($request);
    }
}
