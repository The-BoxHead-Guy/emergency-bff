<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http\Middleware;

use DataEmergencia\EmergencyBff\Http\HttpError;
use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Http\Response;
use DataEmergencia\EmergencyBff\Support\RateLimiter;

/**
 * Applies a per-session sliding-window rate limit.
 */
final class RateLimitMiddleware implements Middleware
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $bucket = $request->method.':'.$request->path;

        if (!$this->limiter->allow($bucket)) {
            throw new HttpError(429, 'rate_limited', 'Too many requests. Please retry later.', [
                'retry_after' => $this->limiter->windowSeconds(),
            ]);
        }

        return $next($request);
    }
}
