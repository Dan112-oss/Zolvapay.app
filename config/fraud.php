<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Velocity limits
    |--------------------------------------------------------------------------
    |
    | Blueprint Section 2.9's example: ">5 transfers in 60 seconds ->
    | flag." Each entry is [max attempts, window in seconds]. Exceeding
    | the limit BLOCKS the request (throws FraudCheckFailedException) —
    | this is a harder stop than the blueprint's "flag" language,
    | deliberately: a check that only logs after the money has already
    | moved is a lot less useful than one that stops it.
    |
    */
    'velocity_limits' => [
        'transfer' => ['max' => 5, 'window_seconds' => 60],
        'withdrawal' => ['max' => 3, 'window_seconds' => 60],
        'conversion' => ['max' => 5, 'window_seconds' => 60],
        'bill_payment' => ['max' => 5, 'window_seconds' => 60],
    ],

    /*
    |--------------------------------------------------------------------------
    | Large transaction alerts
    |--------------------------------------------------------------------------
    |
    | Amounts (in MAJOR units of the given currency) above which a
    | fraud_alerts row is written for admin visibility. Non-blocking —
    | the transaction still completes; this is purely "someone should
    | take a look." Currencies not listed use 'default'.
    |
    */
    'large_transaction_thresholds' => [
        'default' => 5000, // e.g. 5000 NGN, 5000 KES — deliberately conservative, tune per currency
        'USD' => 1000,
        'EUR' => 1000,
        'GBP' => 1000,
    ],

];
