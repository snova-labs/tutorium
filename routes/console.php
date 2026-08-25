<?php

declare(strict_types=1);

use App\Jobs\TakeUsageSnapshotsJob;
use App\Services\ImpersonationService;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work — canonical
|--------------------------------------------------------------------------
|
| Times are UTC. Ordered by hour so that a glance shows what runs when, and
| spaced so that two heavy jobs never contend for the same worker.
|
*/

// Support access that ran past its expiry. Belt and braces alongside token expiry — access left
// open is access nobody revoked.
Schedule::call(fn () => app(ImpersonationService::class)->closeExpired())
    ->everyFiveMinutes()
    ->name('close-expired-support-access');

// The daily measurement every invoice is built from. Early, and before anything that reads it.
Schedule::job(new TakeUsageSnapshotsJob)
    ->dailyAt('02:00')
    ->name('usage-snapshots')
    ->withoutOverlapping();

// Collection, and any plan change whose date has arrived. During working hours, because a failed
// payment sends an email and nobody wants one at 3am.
Schedule::command('platform:dunning')
    ->dailyAt('09:00')
    ->name('dunning')
    ->withoutOverlapping();