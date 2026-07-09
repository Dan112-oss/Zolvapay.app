<?php

namespace App\Services\Fx;

/**
 * Adapter contract for an FX rate data source, following the same
 * pattern as KycProviderInterface (blueprint Phase 1). Every
 * implementation returns rates relative to USD, since that's the
 * lowest common denominator every provider (including free-tier Open
 * Exchange Rates) can supply — FxRateService derives cross rates
 * (e.g. NGN -> EUR) from two USD legs so callers never need to care.
 */
interface FxProviderInterface
{
    /**
     * @return array<string, float> currency_code => units of that
     *                               currency per 1 USD (e.g. ['NGN' => 1550.23, 'EUR' => 0.92])
     */
    public function latestUsdRates(array $currencyCodes): array;
}
