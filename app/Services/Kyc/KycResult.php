<?php

namespace App\Services\Kyc;

/**
 * Plain result object returned by every KycProviderInterface implementation,
 * so KycController doesn't care whether the underlying provider answered
 * synchronously (mock, or a simple vendor) or will call back later via a
 * webhook (most real KYC vendors) — the caller just reads ->status.
 */
class KycResult
{
    public function __construct(
        public readonly string $status, // pending, approved, rejected
        public readonly ?string $reason = null,
        public readonly array $raw = [],
    ) {
    }
}
