<?php

namespace App\Services\Loans;

final class LoanScoreResult
{
    private function __construct(
        public readonly bool $approved,
        public readonly ?int $approvedAmountMinor,
        public readonly ?int $interestRateBps,
        public readonly ?string $rejectionReason,
        public readonly array $context = [],
    ) {
    }

    public static function approved(int $approvedAmountMinor, int $interestRateBps, array $context = []): self
    {
        return new self(true, $approvedAmountMinor, $interestRateBps, null, $context);
    }

    public static function rejected(string $reason): self
    {
        return new self(false, null, null, $reason);
    }
}
