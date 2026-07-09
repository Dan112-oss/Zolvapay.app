<?php

namespace App\Services\PaymentRails;

use Illuminate\Http\Request;

/**
 * Blueprint Section 2.5: "abstracted behind a 'Payment Rail Adapter'
 * interface so each country plugs in without touching core logic."
 *
 * Every method here deals only in minor units and ISO currency codes —
 * an implementation is responsible for converting to/from whatever
 * units its own API expects.
 */
interface PaymentRailAdapterInterface
{
    /**
     * Start a wallet top-up. $payerDetails is provider-agnostic (e.g.
     * ['name' => ..., 'email' => ..., 'phone' => ...]) — an adapter uses
     * whichever of those fields its API needs and ignores the rest.
     */
    public function initiateFunding(
        string $reference,
        int $amountMinor,
        string $currencyCode,
        array $payerDetails,
    ): PaymentRailResult;

    /**
     * Start a withdrawal to an external bank account. $bankDetails is
     * provider-agnostic (e.g. ['bank_code' => ..., 'account_number' =>
     * ..., 'account_name' => ...]) for the same reason as above.
     */
    public function initiateWithdrawal(
        string $reference,
        int $amountMinor,
        string $currencyCode,
        array $bankDetails,
    ): PaymentRailResult;

    /**
     * Whether an inbound webhook request is genuinely from this rail.
     * Must be checked before parseWebhookPayload() is trusted with
     * anything that touches a wallet balance.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Turn this rail's raw webhook payload into the shape
     * PaymentRailService understands, regardless of that rail's own
     * event/field naming.
     */
    public function parseWebhookPayload(array $payload): PaymentRailWebhookEvent;
}
