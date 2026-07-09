<?php

return [

    'name' => env('APP_NAME', 'ZolvaPay'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    'locale' => 'en',

    'fallback_locale' => 'en',

    'faker_locale' => 'en_US',

    /*
    | Required for encrypted cookies, password reset tokens (once that
    | flow exists — see SECURITY_AUDIT.md's open-gaps list), and
    | Laravel's own internal encryption. Generate with:
    |   php artisan key:generate
    */
    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    'maintenance' => [
        'driver' => 'file',
    ],

];
