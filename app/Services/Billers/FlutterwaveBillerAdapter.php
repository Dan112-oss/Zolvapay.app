<?php

namespace App\Services\Billers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Real provider: Flutterwave's Bills API, under the same account/secret
 * key as FlutterwaveAdapter (app/Services/PaymentRails) — one Flutterwave
 * integration covers both transfers and bills.
 *
 * IMPORTANT: same caveat as FlutterwaveAdapter — built against their
 * publicly documented Bills endpoints (GET /bill-categories or
 * /billers/{code}/items, POST /bills), not verified live from inside
 * this environment. Confirm exact field names against Flutterwave's
 * current docs before going live.
 */
class FlutterwaveBillerAdapter implements BillerAdapterInterface
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly ?Client $client = null,
    ) {
        if (empty($this->secretKey)) {
            throw new RuntimeException(
                'PAYMENT_RAIL_SECRET_KEY is not set (Flutterwave bills reuse the same account as '.
                'payment rails). Add it to .env, or set BILLER_PROVIDER=mock for local development.'
            );
        }
    }

    public function listBillers(?string $category = null): array
    {
        $response = $this->get('/bill-categories', $category ? ['query' => ['biller_type' => $category]] : []);

        $items = $response['data'] ?? [];

        return array_map(
            fn (array $item) => new Biller(
                code: (string) ($item['biller_code'] ?? $item['id'] ?? ''),
                name: (string) ($item['name'] ?? $item['biller_name'] ?? 'Unknown biller'),
                category: (string) ($item['biller_type'] ?? $category ?? 'other'),
                currencyCode: $item['country'] ?? null,
            ),
            $items,
        );
    }

    public function payBill(string $reference, string $billerCode, string $customerId, int $amountMinor, string $currencyCode): BillPaymentResult
    {
        $response = $this->post('/bills', [
            'country' => null, // left to the biller_code's own configured country in Flutterwave's dashboard
            'customer' => $customerId,
            'amount' => round($amountMinor / 100, 2),
            'type' => $billerCode,
            'reference' => $reference,
        ]);

        $providerReference = isset($response['data']['reference'])
            ? (string) $response['data']['reference']
            : null;

        if (($response['status'] ?? null) !== 'success') {
            return new BillPaymentResult(
                status: 'failed',
                message: $response['message'] ?? 'Flutterwave rejected the bill payment.',
                raw: $response,
            );
        }

        // Airtime typically settles inline; utility/TV bills often
        // don't — without a per-biller settlement-speed table, treat
        // every accepted request as 'pending' and let the webhook (or,
        // for the rare instant case, a webhook that arrives seconds
        // later) confirm it. Safer to under-promise here than to credit
        // a bill_payments row 'successful' before it truly is.
        return new BillPaymentResult(
            status: 'pending',
            providerReference: $providerReference,
            message: 'Bill payment accepted for processing.',
            raw: $response,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $expected = config('payment_rails.flutterwave.webhook_secret');
        $received = $request->header('verif-hash');

        if (empty($expected) || empty($received)) {
            return false;
        }

        return hash_equals($expected, $received);
    }

    public function parseWebhookPayload(array $payload): BillWebhookEvent
    {
        $data = $payload['data'] ?? [];
        $rawStatus = strtolower((string) ($data['status'] ?? ''));

        $status = match (true) {
            in_array($rawStatus, ['successful', 'success'], true) => 'successful',
            in_array($rawStatus, ['failed', 'failure'], true) => 'failed',
            default => 'pending',
        };

        return new BillWebhookEvent(
            reference: $data['reference'] ?? null,
            providerReference: $data['tx_ref'] ?? null,
            status: $status,
            eventType: $payload['event'] ?? 'unknown',
            raw: $payload,
        );
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, ['json' => $body]);
    }

    private function get(string $path, array $options = []): array
    {
        return $this->request('GET', $path, $options);
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $client = $this->client ?? new Client(['timeout' => 15]);

        try {
            $response = $client->request($method, $this->baseUrl.$path, array_merge($options, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->secretKey,
                    'Content-Type' => 'application/json',
                ],
            ]));
        } catch (GuzzleException $e) {
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $decoded = json_decode((string) $e->getResponse()->getBody(), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            throw new RuntimeException('Flutterwave bills request failed: '.$e->getMessage(), 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Flutterwave returned an unexpected response shape.');
        }

        return $decoded;
    }
}
