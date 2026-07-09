<?php

namespace App\Services\PaymentRails;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Resolves every funding/withdrawal instantly with no external call —
 * mirrors MockKycProvider/MockFxProvider's role for their own modules.
 * Never returns 'pending', so PaymentRailService finalizes the wallet
 * movement immediately at initiation rather than waiting on a webhook.
 */
class MockPaymentRailAdapter implements PaymentRailAdapterInterface
{
    public function initiateFunding(string $reference, int $amountMinor, string $currencyCode, array $payerDetails): PaymentRailResult
    {
        return new PaymentRailResult(
            status: 'successful',
            railReference: 'mock_'.Str::uuid(),
            checkoutUrl: null,
            message: 'Mock funding completed instantly — no real payment page exists.',
            raw: ['reference' => $reference, 'amount_minor' => $amountMinor, 'currency_code' => $currencyCode],
        );
    }

    public function initiateWithdrawal(string $reference, int $amountMinor, string $currencyCode, array $bankDetails): PaymentRailResult
    {
        return new PaymentRailResult(
            status: 'successful',
            railReference: 'mock_'.Str::uuid(),
            checkoutUrl: null,
            message: 'Mock withdrawal completed instantly — no funds actually left ZolvaPay.',
            raw: ['reference' => $reference, 'amount_minor' => $amountMinor, 'currency_code' => $currencyCode],
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // The mock adapter never sends real webhooks (everything
        // resolves synchronously above) — nothing should ever call this
        // for 'mock', but return false rather than true so a
        // misconfigured route can't be tricked into trusting an
        // unverified payload.
        return false;
    }

    public function parseWebhookPayload(array $payload): PaymentRailWebhookEvent
    {
        return new PaymentRailWebhookEvent(
            reference: $payload['reference'] ?? null,
            railTransactionId: $payload['rail_reference'] ?? null,
            status: $payload['status'] ?? 'pending',
            eventType: 'mock.event',
            raw: $payload,
        );
    }
}
