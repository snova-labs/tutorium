<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\TakeUsageSnapshotsJob;
use App\Models\Tenant;
use App\Services\MeteringService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Take today's measurements, or fill in a day that was missed.
 */
final class MeterUsageCommand extends Command
{
    protected $signature = 'platform:meter
        {--date= : The day to measure, defaults to today}
        {--backfill : Reconstruct the day from enrollment history rather than measuring it now}
        {--tenant= : Limit to one account by slug}';

    protected $description = 'Record the daily active-learner count for billing';

    public function handle(MeteringService $metering, TenantContext $tenancy): int
    {
        $date = $this->option('date') === null
            ? CarbonImmutable::now()->startOfDay()
            : CarbonImmutable::parse($this->option('date'))->startOfDay();

        if ($this->option('tenant') !== null) {
            $tenant = $tenancy->withoutScoping(
                fn () => Tenant::query()->where('slug', $this->option('tenant'))->firstOrFail(),
            );

            $snapshot = $this->option('backfill')
                ? $metering->backfill($tenant, $date)
                : $metering->snapshot($tenant, $date);

            $this->info(sprintf(
                '%s — %d active learners on %s%s',
                $tenant->name,
                $snapshot->active_learners,
                $date->toDateString(),
                $snapshot->is_correction ? ' (reconstructed from history)' : '',
            ));

            return self::SUCCESS;
        }

        if ($this->option('backfill')) {
            $this->error('Backfilling one day at a time is deliberate. Name the account with --tenant.');

            return self::FAILURE;
        }

        TakeUsageSnapshotsJob::dispatchSync($date->toDateString());
        $this->info('Snapshots taken for '.$date->toDateString().'.');

        return self::SUCCESS;
    }
}
