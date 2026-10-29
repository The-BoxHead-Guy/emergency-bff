<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\WhatsApp;

/**
 * Builds responders from emergency contacts.
 */
final class ResponderFactory
{
    /**
     * @param array<int, array<string, mixed>> $contacts
     * @return array<int, Responder>
     */
    public function createFromContacts(array $contacts): array
    {
        return array_map(
            static fn (array $contact): Responder => Responder::fromContact($contact),
            array_values($contacts),
        );
    }
}
