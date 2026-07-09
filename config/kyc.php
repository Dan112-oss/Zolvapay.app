<?php

return [

    /*
    |--------------------------------------------------------------------------
    | KYC Provider
    |--------------------------------------------------------------------------
    |
    | 'mock' stores the submission and leaves it for manual admin review —
    | no external vendor is called. Switch to a real vendor slug (e.g.
    | 'smileid') once KYC_API_KEY / KYC_API_SECRET / KYC_PARTNER_ID are set
    | in .env and a matching provider class exists in app/Services/Kyc
    | (see KycProviderFactory).
    |
    */
    'provider' => env('KYC_PROVIDER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    */
    'max_upload_kb' => 5120, // 5MB per document/selfie

    'allowed_mimes' => ['jpg', 'jpeg', 'png', 'pdf'],

];
