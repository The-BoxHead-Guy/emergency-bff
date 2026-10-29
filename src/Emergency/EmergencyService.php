<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency;

use DataEmergencia\EmergencyBff\Api\EmergencyRepository;
use DataEmergencia\EmergencyBff\Api\Profile;
use DataEmergencia\EmergencyBff\Api\ProfileRepository;
use DataEmergencia\EmergencyBff\Api\WhatsAppClient;
use DataEmergencia\EmergencyBff\Emergency\Exception\AlreadyActiveEmergencyException;
use DataEmergencia\EmergencyBff\Emergency\Exception\EmergencyNotFoundException;
use DataEmergencia\EmergencyBff\Emergency\Exception\ForbiddenEmergencyException;
use DataEmergencia\EmergencyBff\Emergency\Exception\MissingContactsException;
use DataEmergencia\EmergencyBff\Emergency\Exception\MissingProfileException;
use DataEmergencia\EmergencyBff\Emergency\Exception\WhatsAppFailureException;
use DataEmergencia\EmergencyBff\WhatsApp\ProductCodeSelector;
use DataEmergencia\EmergencyBff\WhatsApp\Responder;
use DataEmergencia\EmergencyBff\WhatsApp\ResponderFactory;
use DataEmergencia\EmergencyBff\WhatsApp\WhatsAppPayloadBuilder;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates activation, simulacrum, abort, refresh and resume.
 */
final class EmergencyService
{
    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly ResponderFactory $responders,
        private readonly WhatsAppPayloadBuilder $payloads,
        private readonly WhatsAppClient $whatsApp,
        private readonly EmergencyRepository $emergencies,
        private readonly ProductCodeSelector $productCodes = new ProductCodeSelector(),
        private readonly ?LoggerInterface $logger = null,
        private readonly ?IdempotencyStore $idempotency = null,
    ) {
    }

    /**
     * @param array<string, mixed> $geolocation
     */
    public function activate(Identity $identity, array $geolocation, ?string $idempotencyKey = null): EmergencyResult
    {
        $cacheKey = $this->cacheKey('activate', $identity, $idempotencyKey);

        if ($cacheKey !== null && is_array($cached = $this->idempotency?->get($cacheKey))) {
            return EmergencyResult::fromArray($cached);
        }

        $active = $this->emergencies->findActiveByCode($identity->code);

        if ($active !== null) {
            $activeId = (string) ($active['emergency_id'] ?? $active['id'] ?? '');

            throw new AlreadyActiveEmergencyException($identity->code, $activeId);
        }

        $profile = $this->profiles->fetch($identity->code);

        if (!$profile->hasPersonalInfo()) {
            throw new MissingProfileException($identity->code);
        }

        if (!$profile->hasContacts()) {
            throw new MissingContactsException($identity->code);
        }

        $responders = $this->responders->createFromContacts($profile->emergencyContacts);
        $qrCode = $this->productCodes->findValidProductCode($profile->productCodes) ?? $identity->code;

        [$responders, $failures] = $this->sendToResponders(
            $profile,
            $responders,
            fn (array $contact, array $personalInfo): array => $this->payloads->buildEmergencyPayload(
                $contact,
                $geolocation,
                $personalInfo,
                $qrCode,
            ),
        );

        if ($this->allFailed($responders)) {
            throw new WhatsAppFailureException($failures);
        }

        $snapshot = $this->emergencies->create(
            $identity->code,
            array_map(static fn (Responder $responder): array => $responder->toArray(), $responders),
        );

        $result = new EmergencyResult(
            emergencyId: $this->emergencyId($snapshot),
            responders: $responders,
            failures: $failures,
            snapshot: $snapshot,
        );

        $this->logger?->info('emergency.activated', [
            'code' => $identity->code,
            'emergency_id' => $result->emergencyId,
            'responder_count' => count($responders),
            'failed_count' => count($failures),
        ]);

        if ($cacheKey !== null) {
            $this->idempotency?->set($cacheKey, $result->toArray());
        }

        return $result;
    }

    public function simulacrum(Identity $identity, ?string $idempotencyKey = null): EmergencyResult
    {
        $cacheKey = $this->cacheKey('simulacrum', $identity, $idempotencyKey);

        if ($cacheKey !== null && is_array($cached = $this->idempotency?->get($cacheKey))) {
            return EmergencyResult::fromArray($cached);
        }

        $profile = $this->profiles->fetch($identity->code);

        if (!$profile->hasPersonalInfo()) {
            throw new MissingProfileException($identity->code);
        }

        if (!$profile->hasContacts()) {
            throw new MissingContactsException($identity->code);
        }

        $responders = $this->responders->createFromContacts($profile->emergencyContacts);
        $qrCode = $this->productCodes->findValidProductCode($profile->productCodes) ?? $identity->code;

        [$responders, $failures] = $this->sendToResponders(
            $profile,
            $responders,
            fn (array $contact, array $personalInfo): array => $this->payloads->buildSimulacrumPayload(
                $contact,
                $qrCode,
                $personalInfo,
            ),
        );

        if ($this->allFailed($responders)) {
            throw new WhatsAppFailureException($failures);
        }

        $result = new EmergencyResult(null, $responders, $failures, simulated: true);

        $this->logger?->info('emergency.simulacrum', [
            'code' => $identity->code,
            'responder_count' => count($responders),
            'failed_count' => count($failures),
        ]);

        if ($cacheKey !== null) {
            $this->idempotency?->set($cacheKey, $result->toArray());
        }

        return $result;
    }

    public function abort(Identity $identity, string $emergencyId): EmergencyResult
    {
        $active = $this->emergencies->findActiveByCode($identity->code);
        $activeId = $active !== null ? (string) ($active['emergency_id'] ?? $active['id'] ?? '') : '';

        if ($active === null || $activeId === '') {
            throw new EmergencyNotFoundException($emergencyId);
        }

        if ($activeId !== $emergencyId) {
            throw new ForbiddenEmergencyException($emergencyId, $identity->code);
        }

        $profile = $this->profiles->fetch($identity->code);
        $responders = $this->responders->createFromContacts($profile->emergencyContacts);

        [$responders, $failures] = $this->sendToResponders(
            $profile,
            $responders,
            fn (array $contact, array $personalInfo): array => $this->payloads->buildAbortPayload(
                $contact,
                $personalInfo,
            ),
        );

        $this->emergencies->delete($emergencyId);

        $this->logger?->info('emergency.aborted', [
            'code' => $identity->code,
            'emergency_id' => $emergencyId,
            'responder_count' => count($responders),
            'failed_count' => count($failures),
        ]);

        return new EmergencyResult(
            emergencyId: $emergencyId,
            responders: $responders,
            failures: $failures,
            snapshot: ['deleted' => true],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(string $emergencyId): array
    {
        return $this->emergencies->show($emergencyId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resume(Identity $identity): ?array
    {
        return $this->emergencies->findActiveByCode($identity->code);
    }

    /**
     * @param array<int, Responder> $responders
     * @param callable(array<string, mixed>, array<string, mixed>): array<string, mixed> $payloadFactory
     * @return array{0: array<int, Responder>, 1: array<int, array<string, mixed>>}
     */
    private function sendToResponders(Profile $profile, array $responders, callable $payloadFactory): array
    {
        $failures = [];
        $contacts = $profile->emergencyContacts;

        foreach ($responders as $index => $responder) {
            $contact = $contacts[$index] ?? [];

            if ($contact === [] || $responder->phone === '') {
                $responder->failed = true;
                $failures[] = [
                    'phone' => $responder->phone,
                    'message' => 'The emergency contact has no usable phone number.',
                ];
                continue;
            }

            $result = $this->whatsApp->send($payloadFactory($contact, $profile->personalInfo));

            if ($result->success) {
                $responder->sent = true;
                $responder->messageId = $result->messageId();
                continue;
            }

            $responder->failed = true;
            $failures[] = [
                'phone' => $responder->phone,
                'status' => $result->status,
                'message' => $result->error['message'] ?? 'The WhatsApp message could not be sent.',
            ];
        }

        return [$responders, $failures];
    }

    /**
     * @param array<int, Responder> $responders
     */
    private function allFailed(array $responders): bool
    {
        if ($responders === []) {
            return false;
        }

        foreach ($responders as $responder) {
            if (!$responder->failed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function emergencyId(array $snapshot): ?string
    {
        $id = $snapshot['emergency_id'] ?? $snapshot['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function cacheKey(string $operation, Identity $identity, ?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return null;
        }

        return sprintf('%s:%s:%s', $operation, $identity->code, $idempotencyKey);
    }
}
