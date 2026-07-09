<?php

namespace App\Services\Fx;

use App\Models\FxRate;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Blueprint Section 2.4 (FX / Currency Conversion Service).
 *
 * Rates are pulled from the configured provider on an interval (see
 * RefreshFxRates, scheduled roughly every config('fx.cache_minutes'))
 * and cached in the fx_rates table — never fetched live on every
 * request. If nothing fresh exists yet (e.g. the scheduler hasn't run
 * locally), getRate() falls back to a synchronous refresh so the app
 * still works, but production should rely on the schedule.
 *
 * Every quote/refresh inserts a new fx_rates row rather than updating
 * one, so any past conversion can always point back at the exact
 * rate/margin it used (audit requirement, Section 3).
 */
class FxRateService
{
    public function __construct(
        private readonly ?FxProviderInterface $provider = null,
    ) {
    }

    /**
     * Refresh and cache the rate for every ordered pair among the
     * supported currencies. Call this from the scheduled command; also
     * called lazily by getRate() if the cache has gone stale.
     *
     * @return FxRate[] newly inserted rows, keyed by "BASE_QUOTE"
     */
    public function refreshAll(): array
    {
        $currencies = config('fx.supported_currencies', []);
        $provider = $this->provider ?? FxProviderFactory::make();
        $usdRates = $provider->latestUsdRates($currencies);

        foreach ($currencies as $code) {
            if (! isset($usdRates[$code])) {
                throw new InvalidArgumentException(
                    "FX provider did not return a rate for supported currency [{$code}]."
                );
            }
        }

        $now = Carbon::now();
        $inserted = [];

        foreach ($currencies as $base) {
            foreach ($currencies as $quote) {
                if ($base === $quote) {
                    continue;
                }

                $midRate = $this->crossRate($usdRates, $base, $quote);
                $marginBps = $this->marginBpsFor($base, $quote);
                $effectiveRate = $this->applyMargin($midRate, $marginBps);

                $row = FxRate::create([
                    'base_currency' => $base,
                    'quote_currency' => $quote,
                    'mid_rate' => $midRate,
                    'margin_bps' => $marginBps,
                    'effective_rate' => $effectiveRate,
                    'fetched_at' => $now,
                ]);

                $inserted["{$base}_{$quote}"] = $row;
            }
        }

        return $inserted;
    }

    /**
     * The freshest cached rate for a pair, refreshing first if the
     * newest row is missing or older than config('fx.cache_minutes').
     */
    public function getRate(string $base, string $quote): FxRate
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);

        $latest = FxRate::where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->latest('fetched_at')
            ->first();

        $staleAfter = now()->subMinutes((int) config('fx.cache_minutes', 30));

        if (! $latest || $latest->fetched_at->lt($staleAfter)) {
            $this->refreshAll();

            $latest = FxRate::where('base_currency', $base)
                ->where('quote_currency', $quote)
                ->latest('fetched_at')
                ->firstOrFail();
        }

        return $latest;
    }

    /**
     * A ready-to-use quote for converting a specific minor-unit amount
     * from one currency to another, including which fx_rates row backs
     * it (so the caller/controller can pass fx_rate_id through to
     * WalletService::convert() for the audit trail).
     */
    public function quote(string $fromCurrency, string $toCurrency, int $amountMinorFrom): FxQuote
    {
        if (strtoupper($fromCurrency) === strtoupper($toCurrency)) {
            throw new InvalidArgumentException('From and to currency must be different.');
        }

        $rate = $this->getRate($fromCurrency, $toCurrency);

        // effective_rate and amounts are both decimal — round only once,
        // at the very end, to the target currency's minor unit.
        $amountMinorTo = (int) round(((float) $amountMinorFrom) * (float) $rate->effective_rate);

        return new FxQuote(
            fxRateId: $rate->id,
            fromCurrency: strtoupper($fromCurrency),
            toCurrency: strtoupper($toCurrency),
            midRate: (float) $rate->mid_rate,
            marginBps: $rate->margin_bps,
            effectiveRate: (float) $rate->effective_rate,
            amountMinorFrom: $amountMinorFrom,
            amountMinorTo: $amountMinorTo,
            fetchedAt: $rate->fetched_at,
        );
    }

    /**
     * 1 unit of $base in terms of $quote, derived from two USD legs:
     * usdRates[$code] = units of $code per 1 USD, so
     * rate(base -> quote) = usdRates[quote] / usdRates[base].
     */
    private function crossRate(array $usdRates, string $base, string $quote): float
    {
        if ($base === 'USD') {
            return (float) $usdRates[$quote];
        }

        if ($quote === 'USD') {
            return 1.0 / (float) $usdRates[$base];
        }

        return (float) $usdRates[$quote] / (float) $usdRates[$base];
    }

    private function marginBpsFor(string $base, string $quote): int
    {
        $overrides = config('fx.margin_bps', []);

        return (int) ($overrides["{$base}_{$quote}"] ?? $overrides['default'] ?? 150);
    }

    /**
     * The user always gets slightly less than the true mid rate — that
     * spread is the margin. effective_rate = mid_rate * (1 - margin).
     */
    private function applyMargin(float $midRate, int $marginBps): float
    {
        return $midRate * (1 - ($marginBps / 10000));
    }
}
