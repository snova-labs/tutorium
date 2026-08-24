<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\ChangeEnrollmentStatusRequest;
use App\Http\Requests\Api\V1\StoreEnrollmentRequest;
use App\Http\Requests\Api\V1\TransferEnrollmentRequest;
use App\Http\Resources\EnrollmentResource;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Learner;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class EnrollmentController
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('viewAny', Enrollment::class), 403);

        $query = Enrollment::query()->with(['learner', 'status', 'batch'])->orderByDesc('enrolled_on');

        if ($request->filled('batch_id')) {
            $query->where('batch_id', $request->integer('batch_id'));
        }

        if ($request->filled('learner_id')) {
            $query->where('learner_id', $request->integer('learner_id'));
        }

        if ($request->boolean('billable_only')) {
            $query->billable();
        }

        return EnrollmentResource::collection($query->paginate(50));
    }

    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        $enrollment = $this->enrollments->enroll(
            Learner::query()->findOrFail($request->integer('learner_id')),
            Batch::query()->findOrFail($request->integer('batch_id')),
            $request->input('enrolled_on'),
            $request->filled('status_id')
                ? EnrollmentStatus::query()->findOrFail($request->integer('status_id'))
                : null,
        );

        return (new EnrollmentResource($enrollment->load(['learner', 'status', 'batch'])))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Enrollment $enrollment): EnrollmentResource
    {
        abort_unless($request->user()->can('view', $enrollment), 403);

        return new EnrollmentResource(
            $enrollment->load(['learner', 'status', 'batch', 'history.fromStatus', 'history.toStatus']),
        );
    }

    public function changeStatus(ChangeEnrollmentStatusRequest $request, Enrollment $enrollment): EnrollmentResource
    {
        $updated = $this->enrollments->changeStatus(
            $enrollment,
            EnrollmentStatus::query()->findOrFail($request->integer('status_id')),
            $request->input('reason'),
        );

        return new EnrollmentResource($updated->load(['status', 'history.toStatus']));
    }

    /**
     * Move a learner to another batch.
     *
     * Returns the new enrollment. The old one stays, closed and pointing forward, so the learner's
     * path through the institution can be walked in either direction.
     */
    public function transfer(TransferEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $new = $this->enrollments->transfer(
            $enrollment,
            Batch::query()->findOrFail($request->integer('batch_id')),
            $request->input('reason'),
        );

        return (new EnrollmentResource($new->load(['learner', 'status', 'batch'])))
            ->response()->setStatusCode(201);
    }
}
