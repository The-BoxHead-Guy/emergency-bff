<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http\Middleware;

use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Http\Response;

/**
 * Minimal middleware contract for the BFF pipeline.
 */
interface Middleware
{
    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
