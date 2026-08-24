<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReportRunStatus;
use App\Jobs\GenerateReportJob;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\Report;
use App\Models\ReportRun;
use App\Models\ReportTemplate;
use App\Support\Pdf\PdfOptions;
use App\Support\Pdf\PdfRenderer;
use App\Support\Reporting\ReportDataBuilder;
use App\Support\Reporting\ReportRenderer;
use App\Support\Sequences\IdSequenceService;
use App\Support\Time\PeriodBoundary;
use App\Support\Time\PeriodService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReportService
{
    public function __construct(
        private readonly ReportDataBuilder $builder,
        private readonly ReportRenderer $renderer,
        private readonly PdfRenderer $pdf,
        private readonly PeriodService $periods,
        private readonly IdSequenceService $sequences,
    ) {}

    /**
     * Generate one report.
     *
     * @param array<string, mixed> $options
     */
    public function generate(Enrollment $enrollment, PeriodBoundary $period, array $options = []): Report
    {
        $enrollment->loadMissing(['learner', 'batch.course.brand']);

        $readiness = $this->builder->readiness($enrollment, $period);
        $requireComplete = $options['require_complete'] ?? config('reporting.require_complete_period');

        if ($requireComplete && ! $readiness->isReady) {
            throw ValidationException::withMessages([
                'period' => sprintf(
                    'This period is not finished for %s: %s Send anyway only if you mean to.',
                    $enrollment->learner->displayName(),
                    implode(' ', $readiness->gaps),
                ),
            ]);
        }

        $template = $this->templateFor($enrollment);
        $snapshot = $this->builder->build($enrollment, $period);
        $periodRow = $this->periods->ensure($enrollment->batch, $period);

        return DB::transaction(function () use ($enrollment, $periodRow, $template, $snapshot, $options): Report {
            $number = $this->sequences->next('report');

            $report = Report::query()->create([
                'enrollment_id' => $enrollment->getKey(),
                'reporting_period_id' => $periodRow->getKey(),
                'report_template_id' => $template->getKey(),
                'report_run_id' => $options['run_id'] ?? null,
                'number' => $number,
                // Frozen here and never recomputed. This is the evidence.
                'stats_snapshot' => $snapshot->toArray(),
                'generated_by' => Auth::id(),
                'generated_at' => now(),
            ]);

            $report->update($this->store($report, $template, $snapshot->toArray(), $enrollment));

            return $report->refresh();
        });
    }

    /**
     * Queue a report for every active learner in a batch.
     *
     * One job per learner rather than one job for the batch, so a single broken record cannot
     * stop the other thirty-nine reports going out (FR-RPT-3).
     *
     * @param array<string, mixed> $options
     */
    public function queueBatchRun(Batch $batch, ?string $periodLabel, array $options = []): ReportRun
    {
        $batch->loadMissing('course');

        $period = $periodLabel === null
            ? $this->periods->current($batch)
            : $this->periods->forLabel($batch, $periodLabel);

        $periodRow = $this->periods->ensure($batch, $period);

        $enrollments = Enrollment::query()
            ->where('batch_id', $batch->getKey())
            ->active()
            ->pluck('id');

        if ($enrollments->isEmpty()) {
            throw ValidationException::withMessages([
                'batch' => 'There is nobody enrolled in this batch to report on.',
            ]);
        }

        $run = ReportRun::query()->create([
            'batch_id' => $batch->getKey(),
            'reporting_period_id' => $periodRow->getKey(),
            'options' => $options,
            'status' => ReportRunStatus::Queued,
            'total' => $enrollments->count(),
            'started_by' => Auth::id(),
            'started_at' => now(),
        ]);

        foreach ($enrollments as $enrollmentId) {
            GenerateReportJob::dispatch($run->getKey(), (int) $enrollmentId, $period->label, $options);
        }

        return $run->refresh();
    }

    /** Preview a template with neutral sample data — never a real learner. */
    public function preview(ReportTemplate $template): string
    {
        return $this->renderer->preview($template);
    }

    /**
     * Re-render a report exactly as it was sent.
     *
     * Reads the snapshot, never the live records, so a download six weeks later matches the PDF
     * the parent received rather than reflecting corrections made since.
     */
    public function rerender(Report $report): string
    {
        $report->loadMissing(['template', 'enrollment.learner']);

        $html = $this->renderer->render(
            $report->stats_snapshot,
            $report->template ?? $this->templateFor($report->enrollment),
            'Sample Guardian',
            $report->number,
        );

        return $this->pdf->render($html, new PdfOptions(title: $report->number));
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, string>
     */
    private function store(Report $report, ReportTemplate $template, array $snapshot, Enrollment $enrollment): array
    {
        $html = $this->renderer->render(
            $snapshot,
            $template,
            $this->salutationFor($enrollment),
            $report->number,
        );

        $pdf = $this->pdf->render($html, new PdfOptions(title: $report->number));

        $disk = config('reporting.storage.disk');
        $path = sprintf(
            'reports/tenant-%d/%s/%s/%s.pdf',
            $report->tenant_id,
            Str::slug($enrollment->batch->code),
            $snapshot['period']['label'],
            $report->number,
        );

        Storage::disk($disk)->put($path, $pdf);

        return ['file_path' => $path, 'file_disk' => $disk];
    }

    /** How the report opens. Falls back gracefully when a learner has no guardian on file. */
    private function salutationFor(Enrollment $enrollment): string
    {
        $primary = $enrollment->learner->guardians()
            ->wherePivot('receives_reports', true)
            ->orderByPivot('is_primary', 'desc')
            ->first();

        return $primary?->name ?? $enrollment->learner->displayName();
    }

    private function templateFor(Enrollment $enrollment): ReportTemplate
    {
        $courseId = $enrollment->batch->course_id;
        $brandId = $enrollment->batch->course->brand_id;

        return ReportTemplate::query()->where('course_id', $courseId)->first()
            ?? ReportTemplate::query()->whereNull('course_id')->where('brand_id', $brandId)->first()
            ?? ReportTemplate::query()->where('is_default', true)->first()
            ?? ReportTemplate::query()->create([
                'name' => 'Default report',
                'blocks' => config('reporting.default_blocks'),
                'is_default' => true,
                'closing' => 'Warm regards,',
            ]);
    }
}
