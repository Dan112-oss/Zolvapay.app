<?php

return [

    /*
    | Session config exists because Laravel's internals expect it, but
    | as noted in config/auth.php: nothing in this app's actual auth
    | flow is session-based. Every request authenticates via a Sanctum
    | bearer token instead.
    */
    'driver' => env('SESSION_DRIVER', 'redis'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'connection' => env('SESSION_CONNECTION', 'default'),

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'cookie' => env('SESSION_COOKIE', 'zolvapay_session'),

    'path' => '/',

    'domain' => env('SESSION_DOMAIN'),

    'secure' => env('SESSION_SECURE_COOKIE'),

    'http_only' => true,

    'same_site' => 'lax',

];
