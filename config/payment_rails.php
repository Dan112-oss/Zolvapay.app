<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default rail
    |--------------------------------------------------------------------------
    |
    | Used for any currency not listed in 'currency_rail_map' below.
    | 'mock' needs no credentials and resolves funding/withdrawals
    | instantly — useful for local dev and for currencies that don't
    | have a real rail wired up yet.
    |
    */
    'default' => env('PAYMENT_RAIL_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Currency -> rail routing
    |--------------------------------------------------------------------------
    |
    | Which adapter handles which currency. Flutterwave covers a wide
    | range of African currencies through one API/one set of credentials,
    | which is why it's listed for several here — this is config, not a
    | new integration per currency. Currencies not listed fall back to
    | 'default' until a matching rail (e.g. Wise/Stripe for USD/EUR/GBP)
    | is integrated.
    |
    */
    'currency_rail_map' => [
        'NGN' => 'flutterwave',
        'KES' => 'flutterwave',
        // 'XAF' and other Flutterwave-supported currencies can be added
        // here directly — no code change needed, just this map.
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirect URL
    |--------------------------------------------------------------------------
    |
    | Where a hosted checkout (e.g. Flutterwave's payment link) sends the
    | user back to after completing (or abandoning) a funding charge.
    | The actual wallet credit never depends on this — that only happens
    | via the webhook — this is purely where the browser lands.
    |
    */
    'redirect_url' => env('PAYMENT_RAIL_REDIRECT_URL', rtrim(env('APP_URL', ''), '/').'/fund.html'),

    /*
    |--------------------------------------------------------------------------
    | Flutterwave
    |--------------------------------------------------------------------------
    |
    | Verify field names/endpoints against Flutterwave's current API
    | reference before going live — third-party API shapes drift over
    | time and this was built against their v3 Standard/Transfers docs.
    |
    */
    'flutterwave' => [
        'secret_key' => env('PAYMENT_RAIL_SECRET_KEY'),
        'public_key' => env('PAYMENT_RAIL_PUBLIC_KEY'),
        'webhook_secret' => env('PAYMENT_RAIL_WEBHOOK_SECRET'),
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
    ],

];
