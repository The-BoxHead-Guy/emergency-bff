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

$acceptedCodes = ['DABC12', 'A12345', 'ZERO12'];

if ($method === 'GET' && preg_match('#^/v2/fmd/([^/]+)/exists$#', $path, $matches) === 1) {
    $json(['data' => ['attributes' => ['exists' => in_array($matches[1], $acceptedCodes, true)]]]);
}

if ($method === 'GET' && preg_match('#^/v2/fmd/([^/]+)/models/data$#', $path, $matches) === 1) {
    if (!in_array($matches[1], $acceptedCodes, true)) {
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

if ($method === 'GET' && preg_match('#^/v2/credits/([^/]+)/history$#', $path, $matches) === 1) {
    if (!in_array($matches[1], $acceptedCodes, true)) {
        $json(['message' => 'Not found'], 404);
    }

    $rows = [
        [
            'type' => 'credit_history_entry',
            'id' => '3',
            'attributes' => [
                'id' => 3,
                'type' => 'refund',
                'amount' => 1,
                'balance_after' => 10,
                'contacts_count' => 1,
                'rounds' => 10,
                'extra_alerts' => 0,
                'emergency_id' => 501,
                'reference' => 'refund:EMG000501',
                'created_at' => '2026-11-16T09:30:00+00:00',
            ],
        ],
        [
            'type' => 'credit_history_entry',
            'id' => '2',
            'attributes' => [
                'id' => 2,
                'type' => 'emergency',
                'amount' => -1,
                'balance_after' => 9,
                'contacts_count' => 1,
                'rounds' => 9,
                'extra_alerts' => 0,
                'emergency_id' => 500,
                'reference' => 'EMG000500',
                'created_at' => '2026-11-15T14:25:00+00:00',
            ],
        ],
        [
            'type' => 'credit_history_entry',
            'id' => '1',
            'attributes' => [
                'id' => 1,
                'type' => 'recharge',
                'amount' => 10,
                'balance_after' => 10,
                'contacts_count' => 1,
                'rounds' => 10,
                'extra_alerts' => 0,
                'emergency_id' => null,
                'reference' => 'recharge:DEMO-001',
                'created_at' => '2026-11-10T08:00:00+00:00',
            ],
        ],
    ];

    $perPage = (int) ($_GET['per_page'] ?? 15);
    $perPage = max(1, min(100, $perPage));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $total = count($rows);

    $json([
        'meta' => ['status' => 200],
        'data' => [
            'type' => 'credit_history',
            'items' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ],
    ]);
}

if ($method === 'GET' && preg_match('#^/v2/credits/([^/]+)$#', $path, $matches) === 1) {
    if (!in_array($matches[1], $acceptedCodes, true)) {
        $json(['message' => 'Not found'], 404);
    }

    $zero = $matches[1] === 'ZERO12';
    $balance = $zero ? 0 : 9;

    $json([
        'meta' => ['status' => 200],
        'data' => [
            'type' => 'credits',
            'id' => 'credits|'.$matches[1],
            'attributes' => [
                'code' => $matches[1],
                'domain' => 'emergency',
                'balance' => $balance,
                'contacts_count' => 1,
                'rounds' => $balance,
                'extra_alerts' => 0,
                'rounds_label' => $balance.' rounds',
            ],
        ],
    ]);
}

// Current, billed typed templates.
if ($method === 'POST' && preg_match('#^/v2/whatsapp/templates/emergency/(activation|simulacrum|abort)$#', $path, $matches) === 1) {
    $payload = $body();
    file_put_contents(
        $whatsAppLog,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
        FILE_APPEND,
    );

    if (($state['code'] ?? '') === 'ZERO12') {
        $json([
            'meta' => ['status' => 402, 'api_version' => '2.0.0'],
            'errors' => [
                [
                    'status' => '402',
                    'code' => 'INSUFFICIENT_CREDITS',
                    'title' => 'Payment Required',
                    'detail' => 'La cuenta no tiene créditos suficientes para enviar mensajes de WhatsApp.',
                    'source' => ['pointer' => '/data/attributes/recipients'],
                ],
            ],
        ], 402);
    }

    // Deactivates the simulated emergency immediately after a test send.
    if ($matches[1] === 'simulacrum' && ($state['code'] ?? '') !== '') {
        $state['is_active'] = false;
        $save($state);
    }

    $json([
        'meta' => ['status' => 200],
        'data' => [
            'type' => 'whatsapp_template',
            'id' => 'resource|'.bin2hex(random_bytes(4)),
            'attributes' => [
                'data' => [
                    'event_type' => $matches[1],
                    'results' => [
                        [
                            'phone' => $payload['recipients'][0]['phone'] ?? '',
                            'status' => 'sent',
                            'whatsapp_message_id' => 'wamid.demo.'.bin2hex(random_bytes(4)),
                        ],
                    ],
                    'summary' => ['total' => 1, 'sent' => 1, 'failed' => 0],
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

if ($method === 'PUT' && preg_match('#^/v2/emergency/([^/]+)$#', $path, $matches) === 1) {
    if (($state['emergency_id'] ?? null) !== $matches[1]) {
        $json(['message' => 'Not found'], 404);
    }

    $payload = $body();
    $state['is_active'] = (bool) ($payload['is_active'] ?? true);
    $state['responders'] = $payload['responders'] ?? [];
    $save($state);

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
        'meta' => ['status' => 200],
        'data' => [
            'type' => 'response',
            'id' => 'success|'.random_int(1, 999),
            'attributes' => [
                'success' => true,
                'message' => 'WhatsApp template message sent successfully.',
                'id' => random_int(1, 999),
                'details' => [
                    'messaging_product' => 'whatsapp',
                    'messages' => [
                        [
                            'id' => 'wamid.demo.'.bin2hex(random_bytes(4)),
                            'message_status' => 'accepted',
                        ],
                    ],
                ],
            ],
        ],
    ]);
}

$json(['message' => 'Mock route not found: '.$method.' '.$path], 404);
