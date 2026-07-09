<?php

return [

    /*
    | The frontend (public/*.html, wired via routes/web.php) is served
    | from the SAME origin as this API — there is no separate frontend
    | deployment making cross-origin requests today. 'paths' is
    | deliberately just 'api/*' rather than '*', and 'allowed_origins'
    | is empty by default rather than '*', so this doesn't quietly open
    | the API to arbitrary origins the moment someone adds a mobile app
    | or a separate frontend later without thinking about it — add the
    | real origin(s) explicitly when that happens.
    */
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', ''))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
