<?php

namespace App\Services\Fx;

use Illuminate\Support\Carbon;

/**
 * Read-only result of FxRateService::quote() — everything a controller
 * needs to show a preview to the user and, if they confirm, pass through
 * to WalletService::convert() unchanged.
 */
final class FxQuote
{
    public function __construct(
        public readonly string $fxRateId,
        public readonly string $fromCurrency,
        public readonly string $toCurrency,
        public readonly float $midRate,
        public readonly int $marginBps,
        public readonly float $effectiveRate,
        public readonly int $amountMinorFrom,
        public readonly int $amountMinorTo,
        public readonly Carbon $fetchedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'fx_rate_id' => $this->fxRateId,
            'from_currency' => $this->fromCurrency,
            'to_currency' => $this->toCurrency,
            'mid_rate' => round($this->midRate, 6),
            'margin_bps' => $this->marginBps,
            'effective_rate' => round($this->effectiveRate, 6),
            'amount_from' => round($this->amountMinorFrom / 100, 2),
            'amount_to' => round($this->amountMinorTo / 100, 2),
            'fetched_at' => $this->fetchedAt->toIso8601String(),
        ];
    }
}
