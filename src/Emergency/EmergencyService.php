<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency;

use DataEmergencia\EmergencyBff\Api\EmergencyRepository;
use DataEmergencia\EmergencyBff\Api\Profile;
use DataEmergencia\EmergencyBff\Api\ProfileRepository;
use DataEmergencia\EmergencyBff\Api\WhatsAppClient;
use DataEmergencia\EmergencyBff\Emergency\Exception\AlreadyActiveEmergencyException;
use DataEmergencia\EmergencyBff\Emergency\Exception\ApiFailureException;
use DataEmergencia\EmergencyBff\Emergency\Exception\EmergencyNotFoundException;
use DataEmergencia\EmergencyBff\Emergency\Exception\ForbiddenEmergencyException;
use DataEmergencia\EmergencyBff\Emergency\Exception\MissingContactsException;
use DataEmergencia\EmergencyBff\Emergency\Exception\MissingProfileException;
use DataEmergencia\EmergencyBff\Emergency\Exception\WhatsAppFailureException;
use DataEmergencia\EmergencyBff\WhatsApp\MessageResult;
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
        private readonly ?LoggerInterface $logger = null,
        private readonly ?IdempotencyStore $idempotency = null,
    ) {
    }

    public function activate(Identity $identity, ?string $idempotencyKey = null): EmergencyResult
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

        $snapshot = $this->createEmergency($identity->code, $responders, true);
        $emergencyId = $this->emergencyId($snapshot);

        if ($emergencyId === null) {
            throw new ApiFailureException('creating the emergency', 500);
        }

        [$responders, $failures] = $this->sendToResponders(
            $profile,
            $responders,
            fn (array $contact, array $personalInfo): MessageResult => $this->whatsApp->sendActivation(
                Responder::phoneFromContact($contact),
                $this->payloads->buildActivationVariables(
                    (string) ($personalInfo['full_name'] ?? ''),
                    $emergencyId,
                ),
            ),
        );

        if ($this->allFailed($responders)) {
            $this->emergencies->delete($emergencyId);

            throw new WhatsAppFailureException($failures);
        }

        $snapshot = $this->emergencies->update(
            $emergencyId,
            $identity->code,
            $this->responderPayloads($responders),
            true,
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

        $snapshot = $this->createEmergency($identity->code, $responders, false);
        $emergencyId = $this->emergencyId($snapshot);

        if ($emergencyId === null) {
            throw new ApiFailureException('creating the emergency', 500);
        }

        [$responders, $failures] = $this->sendToResponders(
            $profile,
            $responders,
            fn (array $contact, array $personalInfo): MessageResult => $this->whatsApp->sendSimulacrum(
                Responder::phoneFromContact($contact),
                $this->payloads->buildSimulacrumVariables(
                    is_string($contact['name'] ?? null) ? $contact['name'] : '',
                    (string) ($personalInfo['full_name'] ?? ''),
                    $emergencyId,
                ),
            ),
        );

        if ($this->allFailed($responders)) {
            $this->emergencies->delete($emergencyId);

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
            fn (array $contact, array $personalInfo): MessageResult => $this->whatsApp->send(
                $this->payloads->buildAbortPayload($contact, $personalInfo),
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
     * @param callable(array<string, mixed>, array<string, mixed>): MessageResult $sender
     * @return array{0: array<int, Responder>, 1: array<int, array<string, mixed>>}
     */
    private function sendToResponders(Profile $profile, array $responders, callable $sender): array
    {
        $failures = [];
        $contacts = $profile->emergencyContacts;

        foreach ($responders as $index => $responder) {
            $contact = $contacts[$index] ?? [];

            if (!$this->hasUsableContact($contact, $responder)) {
                $failures[] = $this->recordFailure(
                    $responder,
                    'El contacto de emergencia no tiene un número de teléfono utilizable.',
                );

                continue;
            }

            $result = $sender($contact, $profile->personalInfo);

            if (!$result->success) {
                $failures[] = $this->recordFailure(
                    $responder,
                    $result->error['message'] ?? 'No se pudo enviar el mensaje de WhatsApp.',
                    $result->status,
                );

                continue;
            }

            $messageId = $result->messageId();

            if ($messageId === null) {
                $this->logger?->warning('emergency.wamid_missing', [
                    'phone' => $responder->phone,
                    'status' => $result->status,
                ]);

                $failures[] = $this->recordFailure(
                    $responder,
                    'La respuesta de envío de WhatsApp no incluyó un WAMID.',
                    $result->status,
                );

                continue;
            }

            $responder->sent = true;
            $responder->messageId = $messageId;
        }

        return [$responders, $failures];
    }

    /**
     * @param array<string, mixed> $contact
     */
    private function hasUsableContact(array $contact, Responder $responder): bool
    {
        return $contact !== [] && $responder->phone !== '';
    }

    /**
     * Marks the responder as failed and returns its failure entry.
     *
     * @return array<string, mixed>
     */
    private function recordFailure(Responder $responder, string $message, ?int $status = null): array
    {
        $responder->failed = true;

        $failure = ['phone' => $responder->phone];

        if ($status !== null) {
            $failure['status'] = $status;
        }

        $failure['message'] = $message;

        return $failure;
    }

    /**
     * @param array<int, Responder> $responders
     * @return array<string, mixed>
     */
    private function createEmergency(string $code, array $responders, bool $isActive): array
    {
        try {
            return $this->emergencies->create($code, $this->responderPayloads($responders), $isActive);
        } catch (ApiFailureException $exception) {
            if ($exception->status() === 409) {
                throw new AlreadyActiveEmergencyException($code, '');
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, Responder> $responders
     * @return array<int, array<string, mixed>>
     */
    private function responderPayloads(array $responders): array
    {
        return array_map(static fn (Responder $responder): array => $responder->toArray(), $responders);
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
