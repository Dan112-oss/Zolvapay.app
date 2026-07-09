<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Biller provider
    |--------------------------------------------------------------------------
    |
    | 'mock' (default) needs no credentials and settles instantly — good
    | for local dev. 'flutterwave' reuses the SAME Flutterwave account
    | already configured in config/payment_rails.php (one Flutterwave
    | secret key covers both their Transfers and Bills APIs) rather than
    | duplicating credentials here.
    |
    */
    'provider' => env('BILLER_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Categories shown in the UI
    |--------------------------------------------------------------------------
    |
    | Kept here (not hardcoded in the frontend) since which categories
    | are actually usable depends on which billers the provider exposes
    | for your account/country.
    |
    */
    'categories' => ['airtime', 'electricity', 'tv'],

];
