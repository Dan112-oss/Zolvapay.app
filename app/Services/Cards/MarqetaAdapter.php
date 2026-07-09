<?php

namespace App\Services\Cards;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Real processor: Marqeta's Core API v3
 * (https://{base_url}), authenticated via HTTP Basic Auth using an
 * application token (username) and admin access token (password).
 *
 * IMPORTANT: this was built against Marqeta's publicly documented Core
 * API shape (users, cards, cardtransitions, showpan endpoints), not
 * verified live from inside this environment. Confirm exact field
 * names/response shapes against your Marqeta sandbox before going live
 * — in particular, revealCardDetails() below is the single most
 * security-sensitive call in this whole codebase (it's the one place
 * full PAN/CVV leave the processor at all) and deserves a careful
 * side-by-side check against Marqeta's current "showpan" documentation
 * plus their PCI guidance on where that response is allowed to travel.
 */
class MarqetaAdapter implements CardProcessorAdapterInterface
{
    public function __construct(
        private readonly string $applicationToken,
        private readonly string $adminAccessToken,
        private readonly string $cardProductToken,
        private readonly string $baseUrl,
        private readonly ?Client $client = null,
    ) {
        if (empty($this->applicationToken) || empty($this->adminAccessToken)) {
            throw new RuntimeException(
                'CARD_PROCESSOR_API_KEY / CARD_PROCESSOR_API_SECRET are not set. Add your Marqeta '.
                'credentials to .env, or set CARD_PROCESSOR=mock for local development without them.'
            );
        }
    }

    public function issueCard(string $externalUserId, string $cardholderName, string $currencyCode): CardIssuanceResult
    {
        $userToken = $this->ensureMarqetaUser($externalUserId, $cardholderName);

        $response = $this->post('/cards', [
            'user_token' => $userToken,
            'card_product_token' => $this->cardProductToken,
        ]);

        $lastFour = substr((string) ($response['last_four'] ?? '0000'), -4);
        $expiration = (string) ($response['expiration'] ?? ''); // Marqeta returns MMYY
        $expiryMonth = (int) substr($expiration, 0, 2) ?: (int) now()->format('n');
        $expiryYear = $expiration !== '' ? (int) ('20'.substr($expiration, 2, 2)) : (int) now()->addYears(3)->format('Y');

        return new CardIssuanceResult(
            processorCardId: (string) ($response['token'] ?? ''),
            maskedPan: '•••• •••• •••• '.$lastFour,
            lastFour: $lastFour,
            expiryMonth: $expiryMonth,
            expiryYear: $expiryYear,
            raw: $response,
        );
    }

    /**
     * Marqeta models freeze/unfreeze as a "card transition" to a new
     * state rather than a field update on the card itself.
     */
    public function freezeCard(string $processorCardId): bool
    {
        $response = $this->post('/cardtransitions', [
            'card_token' => $processorCardId,
            'state' => 'SUSPENDED',
            'reason_code' => '01', // cardholder request — verify Marqeta's current reason code table
        ]);

        return isset($response['state']) && $response['state'] === 'SUSPENDED';
    }

    public function unfreezeCard(string $processorCardId): bool
    {
        $response = $this->post('/cardtransitions', [
            'card_token' => $processorCardId,
            'state' => 'ACTIVE',
            'reason_code' => '00',
        ]);

        return isset($response['state']) && $response['state'] === 'ACTIVE';
    }

    /**
     * Marqeta enforces velocity/spend controls, so a single flat "spend
     * limit" is modeled as a velocity control window here rather than a
     * field on the card. Confirm this against Marqeta's current
     * "velocity controls" API before relying on it — this is the part
     * of their API most likely to have changed shape.
     */
    public function setSpendLimit(string $processorCardId, ?int $limitMinor, string $currencyCode): bool
    {
        if ($limitMinor === null) {
            $this->delete("/velocitycontrols/card/{$processorCardId}");

            return true;
        }

        $response = $this->post('/velocitycontrols', [
            'card_token' => $processorCardId,
            'currency_code' => $currencyCode,
            'amount_limit' => round($limitMinor / 100, 2),
            'velocity_window' => 'DAY',
        ]);

        return isset($response['token']);
    }

    public function revealCardDetails(string $processorCardId): array
    {
        $response = $this->get("/cards/{$processorCardId}/showpan");

        return [
            'pan' => (string) ($response['pan'] ?? ''),
            'cvv' => (string) ($response['cvv_number'] ?? ''),
            'expiry_month' => (int) substr((string) ($response['expiration'] ?? ''), 0, 2),
            'expiry_year' => (int) ('20'.substr((string) ($response['expiration'] ?? ''), 2, 2)),
        ];
    }

    /**
     * Marqeta users are keyed by a token WE choose, so ZolvaPay's own
     * user id is used directly — POST /users is idempotent against an
     * existing token (returns the existing user rather than erroring),
     * which is what makes this safe to call on every card issuance
     * rather than only the first.
     */
    private function ensureMarqetaUser(string $externalUserId, string $cardholderName): string
    {
        [$firstName, $lastName] = array_pad(explode(' ', $cardholderName, 2), 2, '');

        $response = $this->post('/users', [
            'token' => $externalUserId,
            'first_name' => $firstName,
            'last_name' => $lastName ?: $firstName,
        ]);

        return (string) ($response['token'] ?? $externalUserId);
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, ['json' => $body]);
    }

    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $client = $this->client ?? new Client(['timeout' => 15]);

        try {
            $response = $client->request($method, $this->baseUrl.$path, array_merge($options, [
                'auth' => [$this->applicationToken, $this->adminAccessToken],
                'headers' => ['Content-Type' => 'application/json'],
            ]));
        } catch (GuzzleException $e) {
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $decoded = json_decode((string) $e->getResponse()->getBody(), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            throw new RuntimeException('Marqeta request failed: '.$e->getMessage(), 0, $e);
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Marqeta returned an unexpected response shape.');
        }

        return $decoded;
    }
}
