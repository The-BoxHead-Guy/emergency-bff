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
use DataEmergencia\EmergencyBff\Emergency\Exception\InsufficientCreditsException;
use DataEmergencia\EmergencyBff\Emergency\Exception\MissingContactsException;
use DataEmergencia\EmergencyBff\Emergency\Exception\MissingProfileException;
use DataEmergencia\EmergencyBff\Emergency\Exception\WhatsAppFailureException;
use DataEmergencia\EmergencyBff\Http\ClientMessages;
use DataEmergencia\EmergencyBff\Support\ArrayReader;
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
    ) {}

    public function activate(
        Identity $identity,
        ?string $idempotencyKey = null,
        ?string $reporterPhone = null,
        ?array $geolocation = null,
    ): EmergencyResult {
        $cacheKey = $this->cacheKey('activate', $identity, $idempotencyKey);

        if ($cacheKey !== null && is_array($cached = $this->idempotency?->get($cacheKey))) {
            return EmergencyResult::fromArray($cached);
        }

        $active = $this->emergencies->findActiveByCode($identity->code);

        if ($active !== null) {
            $activeId = (string) (ArrayReader::get($active, 'emergency_id') ?? ArrayReader::get($active, 'id') ?? '');

            throw new AlreadyActiveEmergencyException($identity->code, $activeId);
        }

        $profile = $this->profiles->fetch($identity->code);

        if (! $profile->hasPersonalInfo()) {
            throw new MissingProfileException($identity->code);
        }

        if (! $profile->hasContacts()) {
            throw new MissingContactsException($identity->code);
        }

        $responders = $this->responders->createFromContacts($profile->emergencyContacts);

        $snapshot = $this->createEmergency($identity->code, $responders, true, $reporterPhone, $geolocation, false);
        $emergencyId = $this->emergencyId($snapshot);

        if ($emergencyId === null) {
            throw new ApiFailureException('create_emergency', 500);
        }

        [$responders, $failures] = $this->sendBilledToResponders(
            $identity->code,
            $emergencyId,
            $profile,
            $responders,
            fn (array $contact, array $personalInfo): MessageResult => $this->whatsApp->sendActivation(
                Responder::phoneFromContact($contact),
                $this->payloads->buildActivationVariables(
                    (string) ArrayReader::get($personalInfo, 'full_name', ''),
                    $emergencyId,
                ),
                $emergencyId,
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

        $snapshotReporterPhone = ArrayReader::get($snapshot, 'reporter_phone');

        $result = new EmergencyResult(
            emergencyId: $this->emergencyId($snapshot),
            responders: $responders,
            failures: $failures,
            snapshot: $snapshot,
            reporterPhone: is_string($snapshotReporterPhone) ? $snapshotReporterPhone : $reporterPhone,
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

    public function simulacrum(
        Identity $identity,
        ?string $idempotencyKey = null,
        ?array $geolocation = null,
    ): EmergencyResult {
        $cacheKey = $this->cacheKey('simulacrum', $identity, $idempotencyKey);

        if ($cacheKey !== null && is_array($cached = $this->idempotency?->get($cacheKey))) {
            return EmergencyResult::fromArray($cached);
        }

        $profile = $this->profiles->fetch($identity->code);

        if (! $profile->hasPersonalInfo()) {
            throw new MissingProfileException($identity->code);
        }

        if (! $profile->hasContacts()) {
            throw new MissingContactsException($identity->code);
        }

        $responders = $this->responders->createFromContacts($profile->emergencyContacts);

        $snapshot = $this->createEmergency($identity->code, $responders, true, null, $geolocation, true);
        $emergencyId = $this->emergencyId($snapshot);

        if ($emergencyId === null) {
            throw new ApiFailureException('create_emergency', 500);
        }

        [$responders, $failures] = $this->sendBilledToResponders(
            $identity->code,
            $emergencyId,
            $profile,
            $responders,
            fn (array $contact, array $personalInfo): MessageResult => $this->whatsApp->sendSimulacrum(
                Responder::phoneFromContact($contact),
                $this->payloads->buildSimulacrumVariables(
                    is_string(ArrayReader::get($contact, 'name')) ? ArrayReader::get($contact, 'name') : '',
                    (string) ArrayReader::get($personalInfo, 'full_name', ''),
                    $emergencyId,
                ),
                $emergencyId,
            ),
        );

        if ($this->allFailed($responders)) {
            $this->emergencies->delete($emergencyId);

            throw new WhatsAppFailureException($failures);
        }

        $result = new EmergencyResult(null, $responders, $failures, simulated: true, reporterPhone: null);

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
        $activeId = $active !== null
            ? (string) (ArrayReader::get($active, 'emergency_id') ?? ArrayReader::get($active, 'id') ?? '')
            : '';

        if ($active === null || $activeId === '') {
            throw new EmergencyNotFoundException($emergencyId);
        }

        if ($activeId !== $emergencyId) {
            throw new ForbiddenEmergencyException($emergencyId, $identity->code);
        }

        $profile = $this->profiles->fetch($identity->code);
        $responders = $this->responders->createFromContacts($profile->emergencyContacts);

        [$responders, $failures] = $this->sendToResponders(
            $identity->code,
            $profile,
            $responders,
            fn (array $contact, array $personalInfo): MessageResult => $this->sendAbortToResponder(
                $contact,
                $personalInfo,
                $activeId,
            ),
            billed: false,
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
     * Sends the typed abort message unless the profile has no usable name.
     *
     * @param  array<string, mixed>  $contact
     * @param  array<string, mixed>  $personalInfo
     */
    private function sendAbortToResponder(array $contact, array $personalInfo, string $emergencyId): MessageResult
    {
        $variables = $this->payloads->buildAbortVariables(
            (string) (ArrayReader::get($personalInfo, 'full_name') ?? ''),
        );

        if ($variables['user_name'] === '') {
            return MessageResult::failed([
                'message' => ClientMessages::message(ClientMessages::FAILURE_SEND_FAILED),
            ]);
        }

        return $this->whatsApp->sendAbort(Responder::phoneFromContact($contact), $variables, $emergencyId);
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
     * @param  array<int, Responder>  $responders
     * @param  callable(array<string, mixed>, array<string, mixed>): MessageResult  $sender
     * @return array{0: array<int, Responder>, 1: array<int, array<string, mixed>>}
     */
    private function sendToResponders(string $code, Profile $profile, array $responders, callable $sender, bool $billed = true): array
    {
        $failures = [];
        $contacts = $profile->emergencyContacts;

        foreach ($responders as $index => $responder) {
            $contact = ArrayReader::get($contacts, $index) ?? [];

            if (! $this->hasUsableContact($contact, $responder)) {
                $failures[] = $this->recordFailure(
                    $responder,
                    ClientMessages::message(ClientMessages::FAILURE_CONTACT_UNUSABLE),
                );

                continue;
            }

            $result = $sender($contact, $profile->personalInfo);

            if (! $result->success) {
                $failures[] = $this->recordFailure(
                    $responder,
                    $this->failureMessage($result),
                    $result->status,
                );

                if ($billed && $result->insufficientCredits) {
                    throw new InsufficientCreditsException($code);
                }

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
                    ClientMessages::message(ClientMessages::FAILURE_WAMID_MISSING),
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
     * Sends billed messages and deletes the emergency when credits run out.
     *
     * @param  array<int, Responder>  $responders
     * @param  callable(array<string, mixed>, array<string, mixed>): MessageResult  $sender
     * @return array{0: array<int, Responder>, 1: array<int, array<string, mixed>>}
     */
    private function sendBilledToResponders(
        string $code,
        string $emergencyId,
        Profile $profile,
        array $responders,
        callable $sender,
    ): array {
        try {
            return $this->sendToResponders($code, $profile, $responders, $sender, true);
        } catch (InsufficientCreditsException $exception) {
            $this->deleteAfterFailure($emergencyId, $code);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    private function hasUsableContact(array $contact, Responder $responder): bool
    {
        return $contact !== [] && $responder->phone !== '';
    }

    /**
     * Deletes an emergency created before a failed send and logs cleanup errors.
     */
    private function deleteAfterFailure(string $emergencyId, string $code): void
    {
        try {
            $this->emergencies->delete($emergencyId);
        } catch (\Throwable $exception) {
            $this->logger?->warning('emergency.cleanup_failed', [
                'code' => $code,
                'emergency_id' => $emergencyId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
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
     * Returns the client copy of a failed send.
     */
    private function failureMessage(MessageResult $result): string
    {
        $error = $result->error ?? [];
        $message = ArrayReader::get($error, 'message');

        return is_string($message) && $message !== ''
            ? $message
            : ClientMessages::message(ClientMessages::FAILURE_SEND_FAILED);
    }

    /**
     * @param  array<int, Responder>  $responders
     * @param  array<string, mixed>|null  $geolocation
     * @return array<string, mixed>
     */
    private function createEmergency(
        string $code,
        array $responders,
        bool $isActive,
        ?string $reporterPhone = null,
        ?array $geolocation = null,
        bool $isSimulated = false,
    ): array {
        try {
            return $this->emergencies->create(
                $code,
                $this->responderPayloads($responders),
                $isActive,
                $reporterPhone,
                $geolocation,
                $isSimulated,
            );
        } catch (ApiFailureException $exception) {
            if ($exception->status() === 409) {
                throw new AlreadyActiveEmergencyException($code, '');
            }

            throw $exception;
        }
    }

    /**
     * @param  array<int, Responder>  $responders
     * @return array<int, array<string, mixed>>
     */
    private function responderPayloads(array $responders): array
    {
        return array_map(static fn (Responder $responder): array => $responder->toArray(), $responders);
    }

    /**
     * @param  array<int, Responder>  $responders
     */
    private function allFailed(array $responders): bool
    {
        if ($responders === []) {
            return false;
        }

        foreach ($responders as $responder) {
            if (! $responder->failed) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function emergencyId(array $snapshot): ?string
    {
        $id = ArrayReader::get($snapshot, 'emergency_id') ?? ArrayReader::get($snapshot, 'id');

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
