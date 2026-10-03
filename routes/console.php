<?php

declare(strict_types=1);

use App\Jobs\TakeUsageSnapshotsJob;
use App\Models\SignupAttempt;
use App\Services\ImpersonationService;
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
