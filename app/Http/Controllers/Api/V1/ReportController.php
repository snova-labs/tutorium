<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\Report;
use App\Models\ReportDelivery;
use App\Models\ReportRun;
use App\Models\ReportTemplate;
use App\Services\ReportDeliveryService;
use App\Services\ReportService;
use App\Support\Reporting\ReportDataBuilder;
use App\Support\Time\PeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportDeliveryService $deliveries,
        private readonly ReportDataBuilder $builder,
        private readonly PeriodService $periods,
    ) {}

    /**
     * What would happen if reports were generated now: who is ready, and who has gaps.
     *
     * Shown before the button rather than after, so a coordinator closes the gaps first instead of
     * discovering them in a report a parent already received.
     */
    public function readiness(Request $request, Batch $batch): JsonResponse
    {
        abort_unless($request->user()->can('view', $batch), 403);
        abort_unless($request->user()->can('reports.generate'), 403);

        $batch->load('course');
        $period = $request->filled('period')
            ? $this->periods->forLabel($batch, $request->string('period')->toString())
            : $this->periods->current($batch);

        $rows = Enrollment::query()
            ->with(['learner.guardians', 'batch.course'])
            ->where('batch_id', $batch->getKey())
            ->active()
            ->get()
            ->map(function (Enrollment $enrollment) use ($period): array {
                $readiness = $this->builder->readiness($enrollment, $period);
                $recipients = $enrollment->learner->guardians
                    ->where('pivot.receives_reports', true)->count();

                return [
                    'enrollment_id' => $enrollment->getKey(),
                    'learner' => $enrollment->learner->displayName(),
                    'recipients' => $recipients,
                    'readiness' => $readiness->toArray(),
                    'blocked' => $recipients === 0 && $enrollment->learner->email === null,
                ];
            });

        return response()->json([
            'data' => [
                'period' => $period->toArray(),
                'learners' => $rows,
                'ready' => $rows->where('readiness.is_ready', true)->count(),
                'with_gaps' => $rows->where('readiness.is_ready', false)->count(),
                'without_recipients' => $rows->where('blocked', true)->count(),
            ],
        ]);
    }

    public function generateBatch(Request $request, Batch $batch): JsonResponse
    {
        abort_unless($request->user()->can('view', $batch), 403);
        abort_unless($request->user()->can('reports.generate'), 403);

        $validated = $request->validate([
            'period' => ['nullable', 'string', 'max:60'],
            'send' => ['boolean'],
            'require_complete' => ['boolean'],
        ]);

        // Sending is a separate permission from generating, because reviewing before a report
        // reaches a family is the point of having two steps.
        if ($request->boolean('send')) {
            abort_unless($request->user()->can('reports.send'), 403);
        }

        $run = $this->reports->queueBatchRun($batch, $validated['period'] ?? null, [
            'send' => $request->boolean('send'),
            'require_complete' => $validated['require_complete'] ?? config('reporting.require_complete_period'),
        ]);

        return response()->json([
            'data' => [
                'run_id' => $run->getKey(),
                'total' => $run->total,
                'status' => $run->status->value,
                'message' => "Generating {$run->total} reports. You can leave this page.",
            ],
        ], 202);
    }

    public function run(Request $request, ReportRun $run): JsonResponse
    {
        abort_unless($request->user()->can('reports.generate'), 403);

        return response()->json([
            'data' => [
                'id' => $run->getKey(),
                'status' => $run->status->value,
                'total' => $run->total,
                'succeeded' => $run->succeeded,
                'failed' => $run->failed,
                'progress' => $run->progress(),
                'failures' => $run->failures ?? [],
                'finished_at_utc' => $run->finished_at?->toIso8601String(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('reports.view_archive'), 403);

        $reports = Report::query()
            ->with(['enrollment.learner', 'period', 'deliveries'])
            ->when($request->filled('period'), fn ($q) => $q->whereHas(
                'period',
                fn ($p) => $p->where('label', $request->string('period')->toString()),
            ))
            ->when($request->filled('batch_id'), fn ($q) => $q->whereHas(
                'enrollment',
                fn ($e) => $e->where('batch_id', $request->integer('batch_id')),
            ))
            ->latest('generated_at')
            ->paginate(50);

        return response()->json([
            'data' => $reports->through(fn (Report $report) => [
                'id' => $report->getKey(),
                'number' => $report->number,
                'learner' => $report->enrollment->learner->displayName(),
                'period' => $report->period->label,
                'generated_at_utc' => $report->generated_at?->toIso8601String(),
                'deliveries' => $report->deliveries->map(fn (ReportDelivery $d) => [
                    'to' => $d->to_address,
                    'name' => $d->recipient_name,
                    'status' => $d->status->value,
                    'error' => $d->error,
                    'sent_at_utc' => $d->sent_at?->toIso8601String(),
                ]),
            ])->items(),
            'meta' => ['total' => $reports->total(), 'per_page' => $reports->perPage()],
        ]);
    }

    /** Download exactly what was sent, rebuilt from the snapshot rather than from live records. */
    public function download(Request $request, Report $report): StreamedResponse|Response
    {
        abort_unless($request->user()->can('reports.view_archive'), 403);

        $pdf = $this->reports->rerender($report);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$report->number.'.pdf"',
        ]);
    }

    public function send(Request $request, Report $report): JsonResponse
    {
        abort_unless($request->user()->can('reports.send'), 403);

        $deliveries = $this->deliveries->queue($report);

        return response()->json([
            'data' => [
                'queued' => $deliveries->count(),
                'recipients' => $deliveries->pluck('to_address'),
            ],
        ], 202);
    }

    public function retryDelivery(Request $request, ReportDelivery $delivery): JsonResponse
    {
        abort_unless($request->user()->can('reports.send'), 403);

        $this->deliveries->retry($delivery);

        return response()->json(['data' => ['message' => 'Queued for another attempt.']]);
    }

    /** A preview built from neutral sample data. Never a real learner. */
    public function preview(Request $request, ReportTemplate $template): Response
    {
        abort_unless($request->user()->can('reports.generate'), 403);

        return response($this->reports->preview($template), 200, ['Content-Type' => 'text/html']);
    }
}
