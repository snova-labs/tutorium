<?php

declare(strict_types=1);

use App\Jobs\TakeUsageSnapshotsJob;
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
