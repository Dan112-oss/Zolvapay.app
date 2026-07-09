<?php

namespace App\Console\Commands;

use App\Services\Savings\SavingsService;
use Illuminate\Console\Command;

/**
 * php artisan savings:accrue-interest
 *
 * Blueprint Section 2.8: "interest accrual job (scheduled)."
 *
 * Same scheduling caveat as RefreshFxRates (Phase 4): this repo doesn't
 * have app/Console/Kernel.php or routes/console.php yet. Once one
 * exists:
 *
 *   Schedule::command('savings:accrue-interest')->dailyAt('00:05');
 *
 * IMPORTANT: this must run at most once per day. SavingsService's own
 * docblock explains why it can't safely dedupe a same-day double-run
 * itself — that's this command's responsibility once real scheduling
 * exists (Laravel's ->withoutOverlapping() alone isn't enough; that
 * only prevents concurrent runs, not two runs on the same calendar day
 * if the schedule is ever misconfigured to run more often).
 */
class AccrueSavingsInterest extends Command
{
    protected $signature = 'savings:accrue-interest';

    protected $description = 'Accrue one day of simple interest on every active savings goal';

    public function handle(SavingsService $savingsService): int
    {
        $count = $savingsService->accrueDailyInterestForAllActiveGoals();

        $this->info("Accrued interest on {$count} savings goal(s).");

        return self::SUCCESS;
    }
}
