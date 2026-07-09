<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled jobs
|--------------------------------------------------------------------------
|
| Both of these were flagged as "needs scheduling once this file exists"
| in their own command classes — RefreshFxRates (Phase 4) and
| AccrueSavingsInterest (Phase 9). Now it does.
|
| Actually running these requires a cron entry pointing at the scheduler
| (standard Laravel deployment step, not specific to this project):
|
|   * * * * * cd /path-to-zolvapay && php artisan schedule:run >> /dev/null 2>&1
|
*/

Schedule::command('fx:refresh-rates')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('savings:accrue-interest')
    ->dailyAt('00:05')
    ->withoutOverlapping();
    // AccrueSavingsInterest's own docblock notes withoutOverlapping()
    // alone doesn't guard against two runs landing on the same
    // calendar day if this schedule is ever misconfigured — dailyAt()
    // at a fixed time keeps that from happening in practice, but a
    // same-day dedupe check inside the command itself would be a more
    // robust follow-up if this schedule is ever changed.
