<?php

namespace App\Services\Billers;

use InvalidArgumentException;

class BillerFactory
{
    public static function make(): BillerAdapterInterface
    {
        return self::forProvider(config('billers.provider', 'mock'));
    }

    public static function forProvider(string $provider): BillerAdapterInterface
    {
        return match ($provider) {
            'mock' => new MockBillerAdapter(),
            // Reuses the Flutterwave account already configured for
            // payment rails — see FlutterwaveBillerAdapter's docblock.
            'flutterwave' => new FlutterwaveBillerAdapter(
                config('payment_rails.flutterwave.secret_key'),
                config('payment_rails.flutterwave.base_url'),
            ),
            default => throw new InvalidArgumentException(
                "Unknown biller provider [{$provider}]. Add a matching class and case here."
            ),
        };
    }
}
