<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http\Middleware;

use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Http\Response;
use DataEmergencia\EmergencyBff\Session\SessionManager;

/**
 * Loads and expires the BFF session.
 */
final class SessionMiddleware implements Middleware
{
    public function __construct(private readonly SessionManager $session)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $this->session->load();

        return $next($request);
    }
}
