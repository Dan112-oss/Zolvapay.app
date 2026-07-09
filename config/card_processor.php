<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Card processor
    |--------------------------------------------------------------------------
    |
    | 'mock' issues fake cards instantly with no credentials — good for
    | local dev. Switch to 'marqeta' once real credentials are set below.
    |
    */
    'provider' => env('CARD_PROCESSOR', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Marqeta
    |--------------------------------------------------------------------------
    |
    | Marqeta authenticates over HTTP Basic Auth using an application
    | token (as the username) and an admin access token (as the
    | password), plus every card belongs to a pre-configured "card
    | product" in their dashboard. Verify these against Marqeta's
    | current API reference before going live — this was built against
    | their publicly documented Core API shape, not a live sandbox call.
    |
    */
    'marqeta' => [
        'application_token' => env('CARD_PROCESSOR_API_KEY'),
        'admin_access_token' => env('CARD_PROCESSOR_API_SECRET'),
        'card_product_token' => env('MARQETA_CARD_PRODUCT_TOKEN'),
        'base_url' => env('MARQETA_BASE_URL', 'https://sandbox-api.marqeta.com/v3'),
    ],

];
