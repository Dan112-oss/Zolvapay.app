<?php

namespace App\Services\PaymentRails;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Real rail: Flutterwave's v3 API (https://api.flutterwave.com/v3).
 * Covers a wide range of African currencies through one integration.
 *
 * IMPORTANT: this was built against Flutterwave's publicly documented
 * v3 "Standard" charge and "Transfers" endpoints. Verify request/
 * response field names against their current API reference before
 * going live — third-party API shapes do drift over time, and this
 * has not been run against a live sandbox from inside this environment.
 */
class FlutterwaveAdapter implements PaymentRailAdapterInterface
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly ?Client $client = null,
    ) {
        if (empty($this->secretKey)) {
            throw new RuntimeException(
                'PAYMENT_RAIL_SECRET_KEY is not set. Add your Flutterwave secret key to .env, '.
                'or set PAYMENT_RAIL_PROVIDER=mock for local development without one.'
            );
        }
    }

    /**
     * POST /v3/payments — returns a hosted checkout link. The charge
     * itself is NOT confirmed by this response; only the webhook
     * ('charge.completed') means the money has actually arrived, which
     * is why this always returns status 'pending' on success.
     */
    public function initiateFunding(string $reference, int $amountMinor, string $currencyCode, array $payerDetails): PaymentRailResult
    {
        $response = $this->post('/payments', [
            'tx_ref' => $reference,
            'amount' => $this->toMajorUnits($amountMinor),
            'currency' => $currencyCode,
            'redirect_url' => config('payment_rails.redirect_url'),
            'customer' => [
                'email' => $payerDetails['email'] ?? null,
                'name' => $payerDetails['name'] ?? null,
                'phonenumber' => $payerDetails['phone'] ?? null,
            ],
            'customizations' => [
                'title' => 'ZolvaPay wallet top-up',
            ],
        ]);

        $checkoutUrl = $response['data']['link'] ?? null;

        if (($response['status'] ?? null) !== 'success' || ! $checkoutUrl) {
            return new PaymentRailResult(
                status: 'failed',
                message: $response['message'] ?? 'Flutterwave rejected the funding request.',
                raw: $response,
            );
        }

        return new PaymentRailResult(
            status: 'pending',
            checkoutUrl: $checkoutUrl,
            message: 'Redirect the user to checkout_url to complete payment.',
            raw: $response,
        );
    }

    /**
     * POST /v3/transfers — initiates a payout to a bank account. Like
     * funding, the response only confirms the transfer was ACCEPTED for
     * processing; the webhook ('transfer.completed') confirms it
     * actually landed.
     */
    public function initiateWithdrawal(string $reference, int $amountMinor, string $currencyCode, array $bankDetails): PaymentRailResult
    {
        $response = $this->post('/transfers', [
            'account_bank' => $bankDetails['bank_code'] ?? null,
            'account_number' => $bankDetails['account_number'] ?? null,
            'amount' => $this->toMajorUnits($amountMinor),
            'currency' => $currencyCode,
            'narration' => 'ZolvaPay withdrawal',
            'reference' => $reference,
        ]);

        $railTransactionId = isset($response['data']['id']) ? (string) $response['data']['id'] : null;

        if (($response['status'] ?? null) !== 'success') {
            return new PaymentRailResult(
                status: 'failed',
                message: $response['message'] ?? 'Flutterwave rejected the withdrawal request.',
                raw: $response,
            );
        }

        return new PaymentRailResult(
            status: 'pending',
            railReference: $railTransactionId,
            message: 'Withdrawal accepted for processing.',
            raw: $response,
        );
    }

    /**
     * Flutterwave sends the webhook secret you configured in your
     * dashboard back verbatim in the 'verif-hash' header — a plain
     * string comparison, not an HMAC signature.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $expected = config('payment_rails.flutterwave.webhook_secret');
        $received = $request->header('verif-hash');

        if (empty($expected) || empty($received)) {
            return false;
        }

        return hash_equals($expected, $received);
    }

    public function parseWebhookPayload(array $payload): PaymentRailWebhookEvent
    {
        $data = $payload['data'] ?? [];
        $rawStatus = strtolower((string) ($data['status'] ?? ''));

        $status = match (true) {
            in_array($rawStatus, ['successful', 'success'], true) => 'successful',
            in_array($rawStatus, ['failed', 'failure'], true) => 'failed',
            default => 'pending',
        };

        return new PaymentRailWebhookEvent(
            reference: $data['tx_ref'] ?? $data['reference'] ?? null,
            railTransactionId: isset($data['id']) ? (string) $data['id'] : null,
            status: $status,
            eventType: $payload['event'] ?? 'unknown',
            raw: $payload,
        );
    }

    /**
     * Flutterwave's amount fields are in major units (e.g. 25.50), not
     * minor units — every adapter method above converts at the boundary
     * so the rest of the app never has to think about it.
     */
    private function toMajorUnits(int $amountMinor): float
    {
        return round($amountMinor / 100, 2);
    }

    private function post(string $path, array $body): array
    {
        $client = $this->client ?? new Client(['timeout' => 15]);

        try {
            $response = $client->post($this->baseUrl.$path, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->secretKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
            ]);
        } catch (GuzzleException $e) {
            // A 4xx from Flutterwave (e.g. invalid bank account) still
            // has a useful JSON body — try to recover it instead of
            // only throwing, so initiateWithdrawal()/initiateFunding()
            // can turn it into a normal 'failed' result.
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $decoded = json_decode((string) $e->getResponse()->getBody(), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            throw new RuntimeException('Flutterwave request failed: '.$e->getMessage(), 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Flutterwave returned an unexpected response shape.');
        }

        return $decoded;
    }
}
