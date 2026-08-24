<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreBatchRequest;
use App\Http\Requests\Api\V1\StoreTimetableSlotRequest;
use App\Http\Requests\Api\V1\UpdateBatchRequest;
use App\Http\Resources\BatchResource;
use App\Http\Resources\TimetableSlotResource;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Course;
use App\Services\BatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BatchController
{
    public function __construct(private readonly BatchService $batches) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('viewAny', Batch::class), 403);

        $query = Batch::query()->with(['course', 'branch'])->orderByDesc('starts_on');

        if (! $request->user()->scope_all_branches) {
            $query->whereIn('branch_id', $request->user()->branches()->pluck('branches.id'));
        }

        // A teacher without batch administration sees only what they teach.
        if (! $request->user()->can('batches.manage')) {
            $query->whereHas('teachers', fn ($q) => $q->whereKey($request->user()->getKey()));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return BatchResource::collection($query->paginate(25));
    }

    public function store(StoreBatchRequest $request): JsonResponse
    {
        $course = Course::query()->findOrFail($request->integer('course_id'));
        $branch = Branch::query()->findOrFail($request->integer('branch_id'));

        $batch = $this->batches->create($course, $branch, $request->validated());

        return (new BatchResource($batch->load(['course', 'branch'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, Batch $batch): BatchResource
    {
        abort_unless($request->user()->can('view', $batch), 403);

        return new BatchResource(
            $batch->load(['course', 'branch', 'teachers', 'timetableSlots.sessionType'])->loadCount('sessions'),
        );
    }

    public function update(UpdateBatchRequest $request, Batch $batch): BatchResource
    {
        return new BatchResource($this->batches->update($batch, $request->validated())->load(['course', 'branch']));
    }

    public function addSlot(StoreTimetableSlotRequest $request, Batch $batch): JsonResponse
    {
        $slot = $this->batches->addSlot($batch, $request->validated());

        return (new TimetableSlotResource($slot->load('sessionType')))->response()->setStatusCode(201);
    }

    public function assignTeachers(Request $request, Batch $batch): BatchResource
    {
        abort_unless($request->user()->can('update', $batch), 403);

        $validated = $request->validate([
            'teachers' => ['required', 'array'],
            'teachers.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'teachers.*.role' => ['nullable', 'in:lead,assistant'],
        ]);

        return new BatchResource($this->batches->assignTeachers($batch, $validated['teachers'])->load('teachers'));
    }
}
