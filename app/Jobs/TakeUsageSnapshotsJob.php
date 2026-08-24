<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\MeteringService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The daily measurement.
 *
 * Runs at a fixed UTC hour rather than per-tenant local midnight: a single well-defined instant is
 * easier to reason about than thirty different ones, and the count is a daily fact rather than a
 * boundary-sensitive one.
 */
final class TakeUsageSnapshotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public readonly ?string $date = null) {}

    public function handle(MeteringService $metering): void
    {
        $date = $this->date === null
            ? CarbonImmutable::now()->startOfDay()
            : CarbonImmutable::parse($this->date)->startOfDay();

        $results = $metering->snapshotAll($date);

        Log::info('Usage snapshots taken', [
            'date' => $date->toDateString(),
            'tenants' => count($results),
            'total_learners' => array_sum($results),
        ]);
    }
}
