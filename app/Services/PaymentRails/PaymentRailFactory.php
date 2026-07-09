<?php

namespace App\Services\PaymentRails;

use InvalidArgumentException;

class PaymentRailFactory
{
    /**
     * Resolve the adapter configured for a given currency
     * (config('payment_rails.currency_rail_map'), falling back to
     * config('payment_rails.default')). Used when initiating a funding
     * or withdrawal, where we know the currency but not yet which rail.
     */
    public static function forCurrency(string $currencyCode): PaymentRailAdapterInterface
    {
        $railName = config("payment_rails.currency_rail_map.{$currencyCode}")
            ?? config('payment_rails.default', 'mock');

        return self::forRail($railName);
    }

    /**
     * Resolve an adapter by its rail name directly. Used for inbound
     * webhooks, where the URL already tells us which rail sent it
     * (POST /api/webhooks/payment-rails/{rail}) — we only need to know
     * how to verify/parse its payload, not which currency was involved.
     */
    public static function forRail(string $railName): PaymentRailAdapterInterface
    {
        return match ($railName) {
            'mock' => new MockPaymentRailAdapter(),
            'flutterwave' => new FlutterwaveAdapter(
                config('payment_rails.flutterwave.secret_key'),
                config('payment_rails.flutterwave.base_url'),
            ),
            default => throw new InvalidArgumentException(
                "Unknown payment rail [{$railName}]. Add a matching class and case here."
            ),
        };
    }
}
