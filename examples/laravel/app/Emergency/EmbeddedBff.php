<?php

declare(strict_types=1);

namespace App\Emergency;

use DataEmergencia\EmergencyBff\Api\EmergencyRepository;
use DataEmergencia\EmergencyBff\Api\ProfileRepository;
use DataEmergencia\EmergencyBff\Api\WhatsAppClient;
use DataEmergencia\EmergencyBff\Config\Config;
use DataEmergencia\EmergencyBff\Emergency\CodeValidator;
use DataEmergencia\EmergencyBff\Emergency\EmergencyService;
use DataEmergencia\EmergencyBff\Emergency\InMemoryIdempotencyStore;
use DataEmergencia\EmergencyBff\Http\Kernel;
use DataEmergencia\EmergencyBff\Http\Psr18ApiClient;
use DataEmergencia\EmergencyBff\Session\SessionManager;
use DataEmergencia\EmergencyBff\WhatsApp\ResponderFactory;
use DataEmergencia\EmergencyBff\WhatsApp\WhatsAppPayloadBuilder;
use Illuminate\Contracts\Session\Session;
use Psr\Log\LoggerInterface;

/**
 * Builds the BFF kernel with Laravel's session instead of native PHP sessions.
 *
 * Keep this class out of the request hot path where possible; Laravel can bind
 * it as a singleton. The emergency service is stateless apart from the
 * per-process idempotency cache.
 */
final class EmbeddedBff
{
    public function __construct(
        private readonly Session $session,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function kernel(): Kernel
    {
        $config = Config::fromArray([
            'apiOrigin' => (string) config('services.emergency_bff.api_origin'),
            'apiKey' => (string) config('services.emergency_bff.api_key'),
            'applicationIdentifier' => (string) config(
                'services.emergency_bff.application_identifier'
            ),
            'routePrefix' => (string) config(
                'services.emergency_bff.route_prefix',
                '/de-bff/emergency'
            ),
            'timeout' => (int) config('services.emergency_bff.timeout', 10),
            'retryCount' => (int) config('services.emergency_bff.retry_count', 1),
            'sessionTtl' => (int) config('services.emergency_bff.session_ttl', 3600),
            'qrBaseHost' => (string) config(
                'services.emergency_bff.qr_base_host',
                'zur.dataemergencia.com'
            ),
            'local' => (bool) config('services.emergency_bff.local', false),
        ]);

        $http = Psr18ApiClient::create($config);
        $session = new SessionManager(new LaravelSessionStore($this->session), $config->sessionTtl);

        $payloads = new WhatsAppPayloadBuilder(
            qrBaseHost: $config->qrBaseHost,
            fallbackOriginUrl: $config->fallbackOriginUrl,
            local: $config->local,
        );

        $service = new EmergencyService(
            profiles: new ProfileRepository($http),
            responders: new ResponderFactory(),
            payloads: $payloads,
            whatsApp: new WhatsAppClient($http),
            emergencies: new EmergencyRepository($http),
            logger: $this->logger,
            idempotency: new InMemoryIdempotencyStore(),
        );

        return new Kernel(
            config: $config,
            session: $session,
            validator: new CodeValidator($http),
            emergency: $service,
            logger: $this->logger,
        );
    }
}
