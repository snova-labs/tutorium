<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\CancelSessionRequest;
use App\Http\Requests\Api\V1\GenerateSessionsRequest;
use App\Http\Resources\ClassSessionResource;
use App\Http\Resources\ReportingPeriodResource;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Services\SchedulingService;
use App\Support\Time\PeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ScheduleController
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly PeriodService $periods,
    ) {}

    public function sessions(Request $request, Batch $batch): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('view', $batch), 403);

        $query = $batch->sessions()->with(['sessionType', 'batch'])->orderBy('starts_at_utc');

        if ($request->filled('from')) {
            $query->where('session_local_date', '>=', $request->string('from')->toString());
        }

        if ($request->filled('to')) {
            $query->where('session_local_date', '<=', $request->string('to')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return ClassSessionResource::collection($query->paginate(100));
    }

    /**
     * Generate a batch's sessions for a period or an explicit range.
     *
     * The response reports what was skipped and why, because generation that silently omits three
     * weeks is worse than generation that fails.
     */
    public function generate(GenerateSessionsRequest $request, Batch $batch): JsonResponse
    {
        $result = $request->filled('from')
            ? $this->scheduling->generateRange($batch, $request->string('from')->toString(), $request->string('to')->toString())
            : $this->scheduling->generatePeriod($batch, $request->input('period'));

        return response()->json(['data' => $result->toArray()]);
    }

    public function cancel(CancelSessionRequest $request, ClassSession $session): ClassSessionResource
    {
        $updated = $this->scheduling->cancel($session, $request->string('reason')->toString());

        return new ClassSessionResource($updated->load(['sessionType', 'batch']));
    }

    public function reschedule(Request $request, ClassSession $session): ClassSessionResource
    {
        abort_unless($request->user()->can('update', $session), 403);

        $validated = $request->validate([
            'session_local_date' => ['required', 'date_format:Y-m-d'],
            'start_time_local' => ['required', 'date_format:H:i'],
        ]);

        $updated = $this->scheduling->reschedule(
            $session,
            $validated['session_local_date'],
            $validated['start_time_local'],
        );

        return new ClassSessionResource($updated->load(['sessionType', 'batch']));
    }

    public function periods(Request $request, Batch $batch): JsonResponse
    {
        abort_unless($request->user()->can('view', $batch), 403);

        $current = $this->periods->current($batch);
        $all = collect($this->periods->forBatch($batch))->map->toArray();

        return response()->json([
            'data' => [
                'current' => $current->toArray(),
                'all' => $all,
                'stored' => ReportingPeriodResource::collection(
                    $batch->reportingPeriods()->orderBy('starts_local_date')->get(),
                ),
            ],
        ]);
    }
}
