<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency;

use DataEmergencia\EmergencyBff\Emergency\Exception\InactiveCodeException;
use DataEmergencia\EmergencyBff\Http\ApiClient;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\Endpoints;

/**
 * Validates the identity code against the upstream FMD existence endpoint.
 *
 * Validation reads only a boolean; the profile is fetched at activation.
 */
final class CodeValidator
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    public function isValid(string $code): bool
    {
        if (!Identity::isValid($code)) {
            return false;
        }

        try {
            $response = $this->client->get(Endpoints::fmdExists($code));
        } catch (ApiException $exception) {
            if ($exception->status() === 404) {
                return false;
            }

            throw $exception;
        }

        $body = $response->body();
        $exists = $body['data']['attributes']['exists'] ?? $body['data']['exists'] ?? $body['exists'] ?? false;

        return filter_var($exists, FILTER_VALIDATE_BOOLEAN);
    }

    public function validate(string $code): Identity
    {
        $identity = Identity::fromCode($code);

        if (!$this->isValid($identity->code)) {
            throw new InactiveCodeException($identity->code);
        }

        return $identity;
    }
}
