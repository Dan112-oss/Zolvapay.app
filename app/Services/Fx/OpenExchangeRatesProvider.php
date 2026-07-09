<?php

namespace App\Services\Fx;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Real FX data source: https://openexchangerates.org/api/latest.json
 *
 * The free/"Free" plan only ever quotes against USD (changing `base`
 * requires a paid plan) and only returns whichever `symbols` are
 * requested, which is exactly what latestUsdRates() needs — see
 * FxProviderInterface's docblock for how cross rates are derived from
 * this.
 */
class OpenExchangeRatesProvider implements FxProviderInterface
{
    private const BASE_URL = 'https://openexchangerates.org/api/latest.json';

    public function __construct(
        private readonly string $appId,
        private readonly ?Client $client = null,
    ) {
        if (empty($this->appId)) {
            throw new RuntimeException(
                'FX_API_KEY is not set. Add your Open Exchange Rates App ID to .env, '.
                'or set FX_PROVIDER=mock for local development without one.'
            );
        }
    }

    public function latestUsdRates(array $currencyCodes): array
    {
        $client = $this->client ?? new Client(['timeout' => 8]);

        // USD itself is never returned by the API (it's the implicit
        // base) — request everything else, then fill USD back in as 1.0
        // so callers get a complete map for every currency they asked for.
        $symbols = array_values(array_diff($currencyCodes, ['USD']));

        try {
            $response = $client->get(self::BASE_URL, [
                'query' => [
                    'app_id' => $this->appId,
                    'symbols' => implode(',', $symbols),
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Open Exchange Rates request failed: '.$e->getMessage(), 0, $e);
        }

        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body) || ! isset($body['rates']) || ! is_array($body['rates'])) {
            throw new RuntimeException('Open Exchange Rates returned an unexpected response shape.');
        }

        $rates = $body['rates'];
        $rates['USD'] = 1.0;

        return array_intersect_key($rates, array_flip($currencyCodes));
    }
}
