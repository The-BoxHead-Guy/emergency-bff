<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

use DataEmergencia\EmergencyBff\Config\Config;
use DataEmergencia\EmergencyBff\Emergency\CodeValidator;
use DataEmergencia\EmergencyBff\Emergency\EmergencyResult;
use DataEmergencia\EmergencyBff\Emergency\EmergencyService;
use DataEmergencia\EmergencyBff\Emergency\Exception\AlreadyActiveEmergencyException;
use DataEmergencia\EmergencyBff\Emergency\Exception\EmergencyException;
use DataEmergencia\EmergencyBff\Emergency\Exception\EmergencyNotFoundException;
use DataEmergencia\EmergencyBff\Emergency\Exception\ForbiddenEmergencyException;
use DataEmergencia\EmergencyBff\Emergency\Exception\InactiveCodeException;
use DataEmergencia\EmergencyBff\Emergency\Exception\InvalidCodeException;
use DataEmergencia\EmergencyBff\Emergency\Exception\MissingContactsException;
use DataEmergencia\EmergencyBff\Emergency\Exception\MissingProfileException;
use DataEmergencia\EmergencyBff\Emergency\Exception\WhatsAppFailureException;
use DataEmergencia\EmergencyBff\Emergency\Identity;
use DataEmergencia\EmergencyBff\Http\Middleware\CsrfMiddleware;
use DataEmergencia\EmergencyBff\Http\Middleware\RateLimitMiddleware;
use DataEmergencia\EmergencyBff\Http\Middleware\SameOriginMiddleware;
use DataEmergencia\EmergencyBff\Http\Middleware\SessionMiddleware;
use DataEmergencia\EmergencyBff\Realtime\RealtimeConfig;
use DataEmergencia\EmergencyBff\Session\SessionManager;
use DataEmergencia\EmergencyBff\Support\RateLimiter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * JSON HTTP kernel for the emergency BFF.
 */
final class Kernel
{
    private readonly Router $router;

    private readonly RateLimiter $limiter;

    private string $requestId = '';

    public function __construct(
        private readonly Config $config,
        private readonly SessionManager $session,
        private readonly CodeValidator $validator,
        private readonly EmergencyService $emergency,
        private readonly RealtimeConfig $realtime,
        private readonly ?LoggerInterface $logger = null,
        ?RateLimiter $limiter = null,
    ) {
        $this->limiter = $limiter ?? new RateLimiter($this->session->store());
        $this->router = $this->routes();
    }

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(8));
        $this->requestId = $requestId;

        try {
            $response = $this->pipeline()->handle($request, fn (Request $request): Response => $this->dispatch($request));
        } catch (HttpError $error) {
            $response = $this->error($error->status, $error->errorCode, $error->getMessage(), $error->details);
        } catch (EmergencyException $exception) {
            [$status, $code] = $this->mapEmergencyException($exception);
            $response = $this->error($status, $code, $exception->getMessage(), $exception->details());
        } catch (ApiException $exception) {
            $response = $this->error(502, 'upstream_error', $exception->getMessage(), [
                'status' => $exception->status(),
                'upstream_code' => $exception->errorCode(),
            ]);
        } catch (Throwable $exception) {
            $this->logger?->error('emergency_bff.unhandled_exception', [
                'request_id' => $requestId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $response = $this->error(500, 'internal_error', 'An unexpected error occurred.');
        }

        return $response
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function pipeline(): Middleware\Middleware
    {
        $handler = new class implements Middleware\Middleware {
            public function handle(Request $request, callable $next): Response
            {
                return $next($request);
            }
        };

        $middleware = [
            new SameOriginMiddleware(),
            new SessionMiddleware($this->session),
            new RateLimitMiddleware($this->limiter),
            new CsrfMiddleware($this->session),
        ];

        foreach (array_reverse($middleware) as $layer) {
            $next = $handler;
            $handler = new class($layer, $next) implements Middleware\Middleware {
                public function __construct(
                    private readonly Middleware\Middleware $layer,
                    private readonly Middleware\Middleware $next,
                ) {
                }

                public function handle(Request $request, callable $final): Response
                {
                    return $this->layer->handle(
                        $request,
                        fn (Request $request): Response => $this->next->handle($request, $final),
                    );
                }
            };
        }

        return $handler;
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);

        if ($match === null) {
            $allowed = $this->router->allowedMethods($request->path);

            if ($allowed !== []) {
                throw new HttpError(405, 'method_not_allowed', 'The HTTP method is not allowed for this route.', [
                    'allowed' => $allowed,
                ]);
            }

            throw new HttpError(404, 'not_found', 'The requested route does not exist.');
        }

        return $match['handler']($request, $match['params']);
    }

    private function routes(): Router
    {
        $router = new Router($this->config->routePrefix);

        $router
            ->add('GET', '/health', fn (): Response => Response::json([
                'status' => 'ok',
                'service' => 'dataemergencia/emergency-bff',
            ]))
            ->add('GET', '/config', function (): Response {
                return Response::json([
                    'route_prefix' => $this->config->routePrefix,
                    'realtime' => $this->realtime->toArray(),
                    'csrf_token' => $this->session->csrfToken(),
                ]);
            })
            ->add('POST', '/session', function (Request $request): Response {
                $code = $request->input('code');

                if (!is_string($code) || trim($code) === '') {
                    throw new HttpError(422, 'validation_failed', 'A 6-character identity code is required.', [
                        'field' => 'code',
                    ]);
                }

                $identity = $this->validator->validate(trim($code));

                $this->session->storeIdentity($identity);

                $this->logger?->info('emergency_bff.session_started', ['code' => $identity->code]);

                return Response::json([
                    'code' => $identity->code,
                    'csrf_token' => $this->session->rotateCsrf(),
                    'expires_in' => $this->config->sessionTtl,
                ], 201);
            })
            ->add('GET', '/current', function (): Response {
                $identity = $this->identity();
                $active = $this->emergency->resume($identity);

                if ($active === null) {
                    $this->session->clearEmergency();

                    return Response::json([
                        'active' => false,
                        'emergency' => null,
                        'realtime' => $this->realtime->toArray(),
                    ]);
                }

                $this->session->storeEmergency($active);

                return Response::json([
                    'active' => true,
                    'emergency' => $active,
                    'realtime' => $this->realtime->toArray(),
                ]);
            })
            ->add('POST', '/activate', function (Request $request): Response {
                $identity = $this->identity();
                $geolocation = $this->geolocation($request);
                $result = $this->emergency->activate($identity, $geolocation, $this->idempotencyKey($request));

                if ($result->snapshot !== []) {
                    $this->session->storeEmergency($result->snapshot);
                }

                return Response::json($this->resultPayload($result), 201);
            })
            ->add('POST', '/simulacrum', function (Request $request): Response {
                $identity = $this->identity();

                return Response::json($this->resultPayload($this->emergency->simulacrum(
                    $identity,
                    $this->idempotencyKey($request),
                )));
            })
            ->add('POST', '/{id}/refresh', function (Request $request, array $params): Response {
                $this->identity();
                $snapshot = $this->emergency->refresh((string) $params['id']);
                $this->session->storeEmergency($snapshot);

                return Response::json([
                    'emergency' => $snapshot,
                ]);
            })
            ->add('DELETE', '/{id}', function (Request $request, array $params): Response {
                $identity = $this->identity();
                $result = $this->emergency->abort($identity, (string) $params['id']);
                $this->session->clearEmergency();

                return Response::json($this->resultPayload($result) + ['deleted' => true]);
            });

        return $router;
    }

    private function identity(): Identity
    {
        if (!$this->session->hasIdentity()) {
            throw new HttpError(401, 'unauthenticated', 'Start a session with POST /session first.');
        }

        return $this->session->identity();
    }

    /**
     * @return array<string, mixed>
     */
    private function geolocation(Request $request): array
    {
        $geolocation = $request->input('geolocation', []);

        if (!is_array($geolocation)) {
            throw new HttpError(422, 'validation_failed', 'The geolocation payload must be an object.', [
                'field' => 'geolocation',
            ]);
        }

        $coordinates = isset($geolocation['coords']) && is_array($geolocation['coords'])
            ? $geolocation['coords']
            : $geolocation;

        foreach (['latitude', 'longitude'] as $field) {
            if (isset($coordinates[$field]) && !is_numeric($coordinates[$field])) {
                throw new HttpError(422, 'validation_failed', 'The geolocation coordinates must be numeric.', [
                    'field' => 'geolocation.'.$field,
                ]);
            }
        }

        return $geolocation;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('idempotency-key') ?? $request->input('idempotency_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resultPayload(EmergencyResult $result): array
    {
        $payload = $result->toArray();
        unset($payload['snapshot']);

        return $payload;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function mapEmergencyException(EmergencyException $exception): array
    {
        return match (true) {
            $exception instanceof AlreadyActiveEmergencyException => [409, 'emergency_already_active'],
            $exception instanceof ForbiddenEmergencyException => [403, 'emergency_forbidden'],
            $exception instanceof EmergencyNotFoundException => [404, 'emergency_not_found'],
            $exception instanceof MissingContactsException => [422, 'missing_emergency_contacts'],
            $exception instanceof MissingProfileException => [422, 'missing_medical_profile'],
            $exception instanceof InvalidCodeException => [422, 'invalid_code'],
            $exception instanceof InactiveCodeException => [404, 'inactive_code'],
            $exception instanceof WhatsAppFailureException => [502, 'whatsapp_delivery_failed'],
            default => [500, 'emergency_error'],
        };
    }

    /**
     * @param array<string, mixed> $details
     */
    private function error(int $status, string $code, string $message, array $details = []): Response
    {
        return Response::json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'request_id' => $this->requestId,
            ],
        ], $status);
    }
}
