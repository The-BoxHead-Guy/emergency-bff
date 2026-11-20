<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

use DataEmergencia\EmergencyBff\Support\ArrayReader;

/**
 * Spanish copy for the messages the BFF returns to an external consumer.
 *
 * Internal diagnostics, logs and upstream text are not client copy.
 */
final class ClientMessages
{
    public const NOT_FOUND = 'not_found';

    public const METHOD_NOT_ALLOWED = 'method_not_allowed';

    public const UNAUTHENTICATED = 'unauthenticated';

    public const CROSS_ORIGIN_DENIED = 'cross_origin_denied';

    public const CSRF_FAILED = 'csrf_failed';

    public const RATE_LIMITED = 'rate_limited';

    public const INTERNAL_ERROR = 'internal_error';

    public const UPSTREAM_ERROR = 'upstream_error';

    public const EMERGENCY_ERROR = 'emergency_error';

    public const WHATSAPP_DELIVERY_FAILED = 'whatsapp_delivery_failed';

    public const INSUFFICIENT_CREDITS = 'insufficient_credits';

    public const VALIDATION_FAILED = 'validation_failed';

    public const IDENTITY_CODE_REQUIRED = 'identity_code_required';

    public const GEOLOCATION_OBJECT = 'geolocation_object';

    public const GEOLOCATION_COORDINATES_NUMERIC = 'geolocation_coordinates_numeric';

    public const GEOLOCATION_ACCURACY_NUMERIC = 'geolocation_accuracy_numeric';

    public const REPORTER_PHONE_STRING = 'reporter_phone_string';

    public const REPORTER_PHONE_MAX = 'reporter_phone_max';

    public const REPORTER_PHONE_E164 = 'reporter_phone_e164';

    public const QUERY_PARAM_INTEGER = 'query_param_integer';

    public const QUERY_PARAM_RANGE = 'query_param_range';

    public const FAILURE_CONTACT_UNUSABLE = 'failure_contact_unusable';

    public const FAILURE_SEND_FAILED = 'failure_send_failed';

    public const FAILURE_WAMID_MISSING = 'failure_wamid_missing';

    public const FAILURE_INSUFFICIENT_CREDITS = 'failure_insufficient_credits';

    public const FAILURE_RATE_LIMITED = 'failure_rate_limited';

    public const FAILURE_TRANSPORT = 'failure_transport';

    public const FAILURE_RESULT_MISSING = 'failure_result_missing';

    public const FAILURE_NOT_DELIVERED = 'failure_not_delivered';

    private const GENERIC = 'Se produjo un error inesperado.';

    /**
     * @var array<string, string>
     */
    private const MESSAGES = [
        self::NOT_FOUND => 'La ruta solicitada no existe.',
        self::METHOD_NOT_ALLOWED => 'El método HTTP no está permitido para esta ruta.',
        self::UNAUTHENTICATED => 'Inicie una sesión con POST /session antes de continuar.',
        self::CROSS_ORIGIN_DENIED => 'No se permiten solicitudes desde un origen distinto.',
        self::CSRF_FAILED => 'El token CSRF falta o no es válido.',
        self::RATE_LIMITED => 'Demasiadas solicitudes. Vuelva a intentarlo más tarde.',
        self::INTERNAL_ERROR => 'Se produjo un error inesperado. Vuelva a intentarlo más tarde.',
        self::UPSTREAM_ERROR => 'El servicio de emergencias no respondió correctamente. Vuelva a intentarlo más tarde.',
        self::EMERGENCY_ERROR => 'No se pudo completar la operación de emergencia.',
        self::WHATSAPP_DELIVERY_FAILED => 'No se pudo enviar ningún mensaje de plantilla de WhatsApp.',
        self::INSUFFICIENT_CREDITS => 'No hay créditos suficientes para enviar la alerta.',
        self::VALIDATION_FAILED => 'Los datos de la solicitud no son válidos.',
        self::IDENTITY_CODE_REQUIRED => 'Debe indicar un código de identidad de 6 caracteres.',
        self::GEOLOCATION_OBJECT => 'La geolocalización debe enviarse como un objeto.',
        self::GEOLOCATION_COORDINATES_NUMERIC => 'Las coordenadas de geolocalización deben ser numéricas.',
        self::GEOLOCATION_ACCURACY_NUMERIC => 'La precisión de la geolocalización debe ser numérica.',
        self::REPORTER_PHONE_STRING => 'El teléfono del reportante debe ser texto.',
        self::REPORTER_PHONE_MAX => 'El teléfono del reportante no puede superar los 20 caracteres.',
        self::REPORTER_PHONE_E164 => 'El teléfono del reportante debe ser un número E.164 válido.',
        self::QUERY_PARAM_INTEGER => 'El parámetro de consulta debe ser un número entero.',
        self::QUERY_PARAM_RANGE => 'El valor del parámetro de consulta está fuera del rango permitido.',
        self::FAILURE_CONTACT_UNUSABLE => 'El contacto de emergencia no tiene un número de teléfono utilizable.',
        self::FAILURE_SEND_FAILED => 'No se pudo enviar el mensaje de WhatsApp.',
        self::FAILURE_WAMID_MISSING => 'La respuesta de envío de WhatsApp no incluyó un WAMID.',
        self::FAILURE_INSUFFICIENT_CREDITS => 'No hay créditos suficientes para enviar la alerta.',
        self::FAILURE_RATE_LIMITED => 'El servicio de WhatsApp está limitando los envíos. Vuelva a intentarlo más tarde.',
        self::FAILURE_TRANSPORT => 'No se pudo conectar con el servicio de WhatsApp.',
        self::FAILURE_RESULT_MISSING => 'La respuesta de WhatsApp no incluyó el resultado del destinatario.',
        self::FAILURE_NOT_DELIVERED => 'WhatsApp no confirmó la entrega del mensaje al destinatario.',
    ];

    /**
     * Copies that interpolate a detail value, with the copy used when it is missing.
     *
     * @var array<string, array{template: string, fallback: string}>
     */
    private const PARAMETERIZED = [
        'emergency_already_active' => [
            'template' => 'El código «:code» ya tiene una emergencia activa.',
            'fallback' => 'El código ya tiene una emergencia activa.',
        ],
        'emergency_forbidden' => [
            'template' => 'La emergencia «:emergency_id» no pertenece a la identidad validada.',
            'fallback' => 'La emergencia no pertenece a la identidad validada.',
        ],
        'emergency_not_found' => [
            'template' => 'La emergencia «:emergency_id» no existe o ya no está activa.',
            'fallback' => 'La emergencia no existe o ya no está activa.',
        ],
        'missing_emergency_contacts' => [
            'template' => 'El código «:code» no tiene contactos de emergencia configurados.',
            'fallback' => 'El código no tiene contactos de emergencia configurados.',
        ],
        'missing_medical_profile' => [
            'template' => 'No se encontró un perfil médico para el código «:code».',
            'fallback' => 'No se encontró un perfil médico para el código.',
        ],
        'invalid_code' => [
            'template' => 'El código «:code» no cumple el formato de identidad de 6 caracteres.',
            'fallback' => 'El código no cumple el formato de identidad de 6 caracteres.',
        ],
        'inactive_code' => [
            'template' => 'El código «:code» no está asociado a un perfil médico activo.',
            'fallback' => 'El código no está asociado a un perfil médico activo.',
        ],
    ];

    /**
     * Resolves the Spanish copy for a catalog key.
     *
     * @param array<string, mixed> $params
     */
    public static function message(string $key, array $params = []): string
    {
        $parameterized = ArrayReader::get(self::PARAMETERIZED, $key);

        if ($parameterized !== null) {
            $resolved = self::interpolate(ArrayReader::get($parameterized, 'template'), $params);

            return $resolved ?? ArrayReader::get($parameterized, 'fallback');
        }

        return ArrayReader::get(self::MESSAGES, $key) ?? self::GENERIC;
    }

    /**
     * Tells whether a key has its own copy in the catalog.
     */
    public static function has(string $key): bool
    {
        return ArrayReader::has(self::MESSAGES, $key) || ArrayReader::has(self::PARAMETERIZED, $key);
    }

    /**
     * Lists every catalog key.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        $keys = array_merge(array_keys(self::MESSAGES), array_keys(self::PARAMETERIZED));

        sort($keys);

        return $keys;
    }

    /**
     * Fills the `:placeholder` tokens, or returns null when a value is missing.
     *
     * @param array<string, mixed> $params
     */
    private static function interpolate(string $template, array $params): ?string
    {
        if (preg_match_all('/:([a-z_]+)/', $template, $matches) === 0) {
            return $template;
        }

        foreach ($matches[1] as $placeholder) {
            $value = ArrayReader::get($params, $placeholder);

            if (!is_scalar($value) || trim((string) $value) === '') {
                return null;
            }
        }

        return (string) preg_replace_callback(
            '/:([a-z_]+)/',
            static fn (array $match): string => (string) ArrayReader::get($params, $match[1]),
            $template
        );
    }
}
