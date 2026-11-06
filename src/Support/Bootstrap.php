<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Support;

use DataEmergencia\EmergencyBff\Api\EmergencyRepository;
use DataEmergencia\EmergencyBff\Api\ProfileRepository;
use DataEmergencia\EmergencyBff\Api\WhatsAppClient;
use DataEmergencia\EmergencyBff\Config\Config;
use DataEmergencia\EmergencyBff\Emergency\CodeValidator;
use DataEmergencia\EmergencyBff\Emergency\EmergencyService;
use DataEmergencia\EmergencyBff\Emergency\InMemoryIdempotencyStore;
use DataEmergencia\EmergencyBff\Http\Kernel;
use DataEmergencia\EmergencyBff\Http\Psr18ApiClient;
use DataEmergencia\EmergencyBff\Session\NativeSessionStore;
use DataEmergencia\EmergencyBff\Session\SessionManager;
use DataEmergencia\EmergencyBff\WhatsApp\ResponderFactory;
use DataEmergencia\EmergencyBff\WhatsApp\WhatsAppPayloadBuilder;
use Psr\Log\LoggerInterface;

/**
 * Wires the default native-PHP stack.
 *
 * Hosts that already own their HTTP kernel, session storage or HTTP client can
 * build the individual services themselves instead of using this bootstrap.
 */
final class Bootstrap
{
    public static function create(?array $environment = null, ?LoggerInterface $logger = null): Kernel
    {
        $config = Config::fromEnvironment($environment);
        $http = Psr18ApiClient::create($config);

        $session = new SessionManager(
            new NativeSessionStore(
                sessionName: $config->sessionName,
                cookiePath: $config->cookiePath,
                cookieSecure: $config->cookieSecure,
                cookieHttpOnly: $config->cookieHttpOnly,
                cookieSameSite: $config->cookieSameSite,
            ),
            $config->sessionTtl,
        );

        $payloads = new WhatsAppPayloadBuilder();

        $service = new EmergencyService(
            profiles: new ProfileRepository($http),
            responders: new ResponderFactory(),
            payloads: $payloads,
            whatsApp: new WhatsAppClient($http),
            emergencies: new EmergencyRepository($http),
            logger: $logger,
            idempotency: new InMemoryIdempotencyStore(),
        );

        return new Kernel(
            config: $config,
            session: $session,
            validator: new CodeValidator($http),
            emergency: $service,
            logger: $logger,
        );
    }
}
