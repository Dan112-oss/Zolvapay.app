<?php

namespace App\Services\Kyc;

use InvalidArgumentException;

class KycProviderFactory
{
    /**
     * Resolve the KYC provider adapter configured via KYC_PROVIDER
     * (config/kyc.php). Defaults to the mock provider so the flow works
     * end-to-end before a real vendor contract/API key exists.
     */
    public static function make(): KycProviderInterface
    {
        $provider = config('kyc.provider', 'mock');

        return match ($provider) {
            'mock' => new MockKycProvider(),
            // 'smileid' => new SmileIdProvider(config('kyc.api_key'), ...),
            default => throw new InvalidArgumentException(
                "Unknown KYC provider [{$provider}]. Add a matching class and case here."
            ),
        };
    }
}
