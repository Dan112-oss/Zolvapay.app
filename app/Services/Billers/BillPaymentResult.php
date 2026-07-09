<?php

namespace App\Services\Billers;

/**
 * Same status semantics as PaymentRailResult (Phase 5): 'successful'
 * means settled already (no webhook needed — true for most airtime
 * purchases and for the mock adapter), 'pending' means the provider
 * accepted it but confirmation arrives via webhook (common for
 * electricity/TV), 'failed' means rejected outright (e.g. invalid
 * meter number).
 */
final class BillPaymentResult
{
    public function __construct(
        public readonly string $status, // successful, pending, failed
        public readonly ?string $providerReference = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'successful';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
