<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

/**
 * Endpoints mirrored from the application `endpoints.js` contract.
 *
 * No new upstream REST endpoints are introduced by the BFF.
 */
final class Endpoints
{
    public static function getProfileInfo(string $code): string
    {
        return sprintf('/v2/fmd/%s/models/data?model=personal,emergencia', rawurlencode($code));
    }

    /**
     * Application-accessible existence check.
     *
     * The per-model endpoint (`/v2/fmd/{code}/{model}/exists`) requires a user
     * token; the BFF only has application credentials, so validation uses the
     * boolean FMD existence endpoint instead.
     */
    public static function fmdExists(string $code): string
    {
        return sprintf('/v2/fmd/%s/exists', rawurlencode($code));
    }

    public static function postState(): string
    {
        return '/v2/emergency';
    }

    public static function showState(string $emergencyId): string
    {
        return self::statePath($emergencyId);
    }

    public static function update(string $emergencyId): string
    {
        return self::statePath($emergencyId);
    }

    public static function deleteState(string $emergencyId): string
    {
        return self::statePath($emergencyId);
    }

    private static function statePath(string $emergencyId): string
    {
        return sprintf('/v2/emergency/%s', rawurlencode($emergencyId));
    }

    public static function findActiveByQrCode(string $code): string
    {
        return sprintf('/v2/emergency/qr/%s/active', rawurlencode($code));
    }

    public static function whatsappTemplates(): string
    {
        return '/v2/whatsapp/templates/send';
    }

    public static function whatsappEmergencyActivation(): string
    {
        return '/v2/whatsapp/templates/emergency/activation';
    }

    public static function whatsappEmergencySimulacrum(): string
    {
        return '/v2/whatsapp/templates/emergency/simulacrum';
    }
}
