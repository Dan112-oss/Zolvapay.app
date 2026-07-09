<?php

namespace App\Console\Commands;

use App\Services\Fx\FxRateService;
use Illuminate\Console\Command;

/**
 * php artisan fx:refresh-rates
 *
 * Pulls fresh rates for every supported currency pair and caches them in
 * fx_rates (blueprint Section 2.4: "pulls live rates ... on an interval,
 * cached, not per-request"). FxRateService::getRate() also falls back to
 * calling this logic synchronously if the cache is stale, but that's a
 * safety net — this command should be scheduled to run on roughly the
 * same cadence as config('fx.cache_minutes') so requests never pay for
 * a live provider call.
 *
 * This project's scaffold doesn't include app/Console/Kernel.php or
 * routes/console.php yet. Once one exists, schedule this with:
 *
 *   Schedule::command('fx:refresh-rates')->everyThirtyMinutes();
 *
 * (adjust the interval to match FX_CACHE_MINUTES in .env).
 */
class RefreshFxRates extends Command
{
    protected $signature = 'fx:refresh-rates';

    protected $description = 'Fetch and cache the latest FX rates for all supported currency pairs';

    public function handle(FxRateService $fxRateService): int
    {
        $this->info('Refreshing FX rates for: '.implode(', ', config('fx.supported_currencies', [])));

        $rates = $fxRateService->refreshAll();

        foreach ($rates as $pair => $rate) {
            $this->line("  {$pair}: mid={$rate->mid_rate}  effective={$rate->effective_rate}  (margin {$rate->margin_bps} bps)");
        }

        $this->info(count($rates).' rate(s) cached.');

        return self::SUCCESS;
    }
}
