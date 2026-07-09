<?php

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    /*
    | 'web' is unused in practice — every route in this app is API/bearer-
    | token (AuthController issues Sanctum tokens, nothing relies on
    | session-based auth) — but Laravel's internals expect a default
    | guard to exist. Sanctum's own EnsureFrontendRequestsAreStateful/
    | token guard (used via the auth:sanctum middleware throughout
    | routes/api.php) resolves users through the 'users' provider below
    | directly, independent of this 'guards' list.
    */
    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
