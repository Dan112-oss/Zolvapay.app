<?php

return [

    /*
    | Every external vendor this project actually integrates with has
    | its own dedicated config file (config/fx.php, config/kyc.php,
    | config/payment_rails.php, config/card_processor.php,
    | config/billers.php) rather than living here — this file exists
    | because Laravel's internals expect it (e.g. the 'postmark'/'ses'
    | mail transports below, if MAIL_MAILER is ever switched to one of
    | them), not because anything in this app currently reads from it.
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];
