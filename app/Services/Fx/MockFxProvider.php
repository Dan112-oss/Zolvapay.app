<?php

namespace App\Services\Fx;

/**
 * Fixed, clearly-approximate rates so the full FX flow (quote, convert,
 * ledger entries) works end-to-end before an Open Exchange Rates App ID
 * exists — mirrors MockKycProvider's role in Phase 1.
 */
class MockFxProvider implements FxProviderInterface
{
    /**
     * Units of each currency per 1 USD. Deliberately static — never call
     * this in a context where the real market rate matters.
     */
    private const FIXED_USD_RATES = [
        'USD' => 1.0,
        'NGN' => 1550.0,
        'EUR' => 0.92,
        'GBP' => 0.79,
        'KES' => 129.0,
    ];

    public function latestUsdRates(array $currencyCodes): array
    {
        return array_intersect_key(self::FIXED_USD_RATES, array_flip($currencyCodes));
    }
}
