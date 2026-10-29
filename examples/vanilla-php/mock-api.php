<?php

declare(strict_types=1);

/**
 * Tiny stateful mock of the upstream DataEmergencia API.
 *
 * Run with: php -S 127.0.0.1:8081 mock-api.php
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$stateFile = sys_get_temp_dir().'/de-bff-example-state.json';
$whatsAppLog = sys_get_temp_dir().'/de-bff-example-whatsapp.log';

$state = is_file($stateFile)
    ? (array) json_decode((string) file_get_contents($stateFile), true)
    : [];

$save = static function (array $state) use ($stateFile): void {
    file_put_contents($stateFile, json_encode($state));
};

$json = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$body = static function (): array {
    $raw = (string) file_get_contents('php://input');

    return $raw === '' ? [] : (array) json_decode($raw, true);
};

if ($method === 'GET' && preg_match('#^/v2/fmd/([^/]+)/exists$#', $path, $matches) === 1) {
    $json(['data' => ['attributes' => ['exists' => in_array($matches[1], ['DABC12', 'A12345'], true)]]]);
}

if ($method === 'GET' && preg_match('#^/v2/fmd/([^/]+)/models/data$#', $path, $matches) === 1) {
    if (!in_array($matches[1], ['DABC12', 'A12345'], true)) {
        $json(['message' => 'Not found'], 404);
    }

    $json([
        'meta' => ['status' => 200],
        'data' => [
            'attributes' => [
                'data' => [
                    'product_codes' => ['dte_d' => 'DABC12'],
                    'fmd_code' => 'DABC12',
                    'personal' => [
                        'personal_info' => [
                            'full_name' => 'Ada Lovelace',
                            'nationality' => 'British',
                        ],
                    ],
                    'emergencia' => [
                        'emergency_info' => [
                            [
                                'name' => 'Grace Hopper',
                                'relationship' => 'Friend',
                                'position' => 1,
                                'phones' => [
                                    ['area_code' => '57', 'number' => '3001234567'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);
}

if ($method === 'POST' && $path === '/v2/emergency') {
    $payload = $body();
    $id = 'EMG'.substr((string) time(), -6);
    $state = [
        'code' => (string) ($payload['initiator_id'] ?? $state['code'] ?? ''),
        'emergency_id' => $id,
        'is_active' => (bool) ($payload['is_active'] ?? true),
        'responders' => $payload['responders'] ?? [],
    ];
    $save($state);

    $json(['data' => ['attributes' => $state]]);
}

if ($method === 'GET' && preg_match('#^/v2/emergency/qr/([^/]+)/active$#', $path, $matches) === 1) {
    if (($state['code'] ?? null) !== $matches[1] || empty($state['is_active'])) {
        $json(['message' => 'No active emergency'], 404);
    }

    $json(['data' => ['attributes' => $state]]);
}

if ($method === 'GET' && preg_match('#^/v2/emergency/([^/]+)$#', $path, $matches) === 1) {
    if (($state['emergency_id'] ?? null) !== $matches[1]) {
        $json(['message' => 'Not found'], 404);
    }

    $json(['data' => ['attributes' => $state]]);
}

if ($method === 'DELETE' && preg_match('#^/v2/emergency/([^/]+)$#', $path, $matches) === 1) {
    if (($state['emergency_id'] ?? null) !== $matches[1]) {
        $json(['message' => 'Not found'], 404);
    }

    $state['is_active'] = false;
    $save($state);
    http_response_code(204);
    exit;
}

if ($method === 'POST' && $path === '/v2/whatsapp/templates/send') {
    $payload = $body();
    file_put_contents(
        $whatsAppLog,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
        FILE_APPEND,
    );

    $json([
        'success' => true,
        'data' => [
            'message_id' => 'wamid.demo.'.bin2hex(random_bytes(4)),
        ],
    ]);
}

$json(['message' => 'Mock route not found: '.$method.' '.$path], 404);
