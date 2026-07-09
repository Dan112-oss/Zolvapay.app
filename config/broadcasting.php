<?php

return [

    /*
    | Matches the blueprint's own tech stack choice (Section 4): "a
    | lightweight Node service handles live balance updates/
    | notifications while PHP remains the core," via Laravel Echo +
    | Soketi/Pusher. Nothing in this Laravel codebase has actually
    | published to a broadcast channel yet — no controller/job
    | broadcasts an event — so this config exists for when that
    | companion service gets built, not because it's wired up today.
    */
    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'host' => env('PUSHER_HOST', '127.0.0.1'),
                'port' => env('PUSHER_PORT', 6001),
                'scheme' => env('PUSHER_SCHEME', 'http'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'http') === 'https',
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
