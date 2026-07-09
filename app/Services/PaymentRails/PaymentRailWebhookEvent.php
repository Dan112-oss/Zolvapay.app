<?php

namespace App\Services\PaymentRails;

/**
 * Normalized shape every adapter's parseWebhookPayload() must produce,
 * regardless of that rail's actual payload structure — PaymentRailService
 * only ever deals with this, never a raw provider payload.
 */
final class PaymentRailWebhookEvent
{
    public function __construct(
        public readonly ?string $reference, // our tx_ref, echoed back by the rail
        public readonly ?string $railTransactionId,
        public readonly string $status, // successful, pending, failed
        public readonly string $eventType, // raw event name, kept for logging only
        public readonly array $raw = [],
    ) {
    }
}
