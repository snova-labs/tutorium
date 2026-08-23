<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReportRunStatus;
use App\Models\Enrollment;
use App\Models\ReportRun;
use App\Services\ReportDeliveryService;
use App\Services\ReportService;
use App\Support\Tenancy\TenantAware;
use App\Support\Time\PeriodService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Generates one learner's report.
 *
 * One job per learner, deliberately. A batch-wide job would mean one corrupt record stopping
 * thirty-nine other families from getting anything (FR-RPT-3).
 */
final class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public int $tries = 3;

    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly int $runId,
        public readonly int $enrollmentId,
        public readonly string $periodLabel,
        public readonly array $options = [],
    ) {
        $this->initializeTenantAware();
    }

    public function handle(
        ReportService $reports,
        ReportDeliveryService $deliveries,
        PeriodService $periods,
    ): void {
        $run = ReportRun::query()->findOrFail($this->runId);

        if ($run->status === ReportRunStatus::Queued) {
            $run->update(['status' => ReportRunStatus::Running]);
        }

        $enrollment = Enrollment::query()->with(['learner', 'batch.course.brand'])->find($this->enrollmentId);

        if ($enrollment === null) {
            $run->recordFailure('Unknown learner', 'The enrollment no longer exists.');
            $this->finishIfDone($run);

            return;
        }

        try {
            $period = $periods->forLabel($enrollment->batch, $this->periodLabel);

            $report = $reports->generate($enrollment, $period, $this->options + ['run_id' => $run->getKey()]);

            if ($this->options['send'] ?? false) {
                $deliveries->queue($report);
            }

            $run->increment('succeeded');
        } catch (Throwable $e) {
            // Recorded against the run and moved on. The failure is visible and retryable rather
            // than silently missing from a batch of reports.
            $run->recordFailure($enrollment->learner->displayName(), $e->getMessage());
        }

        $this->finishIfDone($run->refresh());
    }

    private function finishIfDone(ReportRun $run): void
    {
        if ($run->succeeded + $run->failed < $run->total) {
            return;
        }

        $run->update([
            'status' => $run->failed > 0 ? ReportRunStatus::CompletedWithFailures : ReportRunStatus::Completed,
            'finished_at' => now(),
        ]);
    }
}
