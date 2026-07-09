<?php

namespace App\Services\Billers;

final class BillWebhookEvent
{
    public function __construct(
        public readonly ?string $reference,
        public readonly ?string $providerReference,
        public readonly string $status, // successful, pending, failed
        public readonly string $eventType,
        public readonly array $raw = [],
    ) {
    }
}
