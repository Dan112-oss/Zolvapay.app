<?php

namespace App\Services\Fx;

use InvalidArgumentException;

class FxProviderFactory
{
    /**
     * Resolve the FX provider adapter configured via FX_PROVIDER
     * (config/fx.php). Defaults to the mock provider so the flow works
     * end-to-end before a real Open Exchange Rates App ID exists.
     */
    public static function make(): FxProviderInterface
    {
        $provider = config('fx.provider', 'mock');

        return match ($provider) {
            'mock' => new MockFxProvider(),
            'openexchangerates' => new OpenExchangeRatesProvider(config('fx.api_key')),
            default => throw new InvalidArgumentException(
                "Unknown FX provider [{$provider}]. Add a matching class and case here."
            ),
        };
    }
}
