<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FX Provider
    |--------------------------------------------------------------------------
    |
    | 'mock' returns fixed, clearly-fake rates and never makes a network
    | call — used for local dev when FX_API_KEY isn't set. Switch to
    | 'openexchangerates' once FX_API_KEY is set in .env (see
    | FxProviderFactory).
    |
    */
    'provider' => env('FX_PROVIDER', 'mock'),

    'api_key' => env('FX_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base currency
    |--------------------------------------------------------------------------
    |
    | Open Exchange Rates' free tier always quotes rates against USD —
    | changing the base requires their paid tier. Every cross rate this
    | app computes (e.g. NGN -> EUR) is derived by combining two USD legs
    | in FxRateService, so the app itself is never limited to USD-only
    | conversions even though the provider is.
    |
    */
    'base_currency' => 'USD',

    /*
    |--------------------------------------------------------------------------
    | Supported currencies
    |--------------------------------------------------------------------------
    |
    | Every currency a wallet is allowed to hold/convert into. Keep this
    | in sync with what WalletController/TransferController accept —
    | there's no DB constraint enforcing it (currency_code is a free
    | CHAR(3)), so this list is the single source of truth for validation.
    |
    */
    'supported_currencies' => ['USD', 'NGN', 'EUR', 'GBP', 'KES'],

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long a cached fx_rates row is considered fresh before
    | FxRateService triggers a new refresh. The blueprint calls for
    | polling "on an interval (cached, not per-request)" — this is that
    | interval. RefreshFxRates should also be scheduled to run on roughly
    | this cadence so the cache rarely expires mid-request.
    |
    */
    'cache_minutes' => (int) env('FX_CACHE_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Margin (spread), in basis points
    |--------------------------------------------------------------------------
    |
    | Applied to the provider's mid_rate to produce effective_rate — the
    | rate actually given to the user. 150 bps = 1.50%. Per-pair overrides
    | key on "FROM_TO" (e.g. 'NGN_USD' => 200) and fall back to 'default'.
    |
    */
    'margin_bps' => [
        'default' => (int) env('FX_DEFAULT_MARGIN_BPS', 150),
    ],

];
