<?php

declare(strict_types=1);

use App\Jobs\TakeUsageSnapshotsJob;
use App\Models\SignupAttempt;
use App\Services\ImpersonationService;
use App\Services\SignupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Schedule::call(fn () => app(ImpersonationService::class)->closeExpired())
    ->everyFiveMinutes();

Schedule::job(new TakeUsageSnapshotsJob)->dailyAt('02:00');

Schedule::command('platform:trials')->dailyAt('08:00')->name('trials');
Schedule::call(fn () => SignupAttempt::query()
    ->where('attempted_at', '<', now()->subDays(14))->delete())
    ->weekly()->name('purge-signup-attempts');
// Confirmation links that can no longer be used, with the form answers they held.
Schedule::call(fn () => app(SignupService::class)->purgeExpired())
    ->daily()->name('purge-pending-signups');

// Nightly backup, and a weekly restore of it that proves it is usable (SL-415). The drill runs
// after Sunday's backup, so it always restores the newest one.
Schedule::command('platform:backup')->dailyAt('01:30')->name('backup')->withoutOverlapping();
Schedule::command('platform:restore-drill')->weeklyOn(0, '03:00')->name('restore-drill')->withoutOverlapping();
