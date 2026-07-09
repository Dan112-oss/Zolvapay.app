<?php

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        /*
        | 'local' is where KYC documents live (KycController::submit(),
        | KycAdminController::document()) — root is storage/app/private,
        | which is NEVER web-accessible directly. The only way to view a
        | document is KycAdminController::document(), which streams it
        | through the app and is gated by the 'admin' middleware.
        */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
