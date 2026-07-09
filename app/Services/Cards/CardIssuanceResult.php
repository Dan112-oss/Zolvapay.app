<?php

namespace App\Services\Cards;

/**
 * Result of CardProcessorAdapterInterface::issueCard(). Only ever
 * carries what's safe to persist (see Card model's docblock) — full
 * PAN/CVV never appear here.
 */
final class CardIssuanceResult
{
    public function __construct(
        public readonly string $processorCardId,
        public readonly string $maskedPan,
        public readonly string $lastFour,
        public readonly int $expiryMonth,
        public readonly int $expiryYear,
        public readonly array $raw = [],
    ) {
    }
}
