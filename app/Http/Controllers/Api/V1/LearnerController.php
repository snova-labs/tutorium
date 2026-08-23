<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreLearnerRequest;
use App\Http\Requests\Api\V1\UpdateLearnerRequest;
use App\Http\Resources\LearnerResource;
use App\Models\Batch;
use App\Models\Learner;
use App\Services\EnrollmentService;
use App\Services\GuardianService;
use App\Services\LearnerService;
use App\Support\People\DuplicateCandidate;
use App\Support\People\DuplicateDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class LearnerController
{
    public function __construct(
        private readonly LearnerService $learners,
        private readonly GuardianService $guardians,
        private readonly EnrollmentService $enrollments,
        private readonly DuplicateDetector $duplicates,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('viewAny', Learner::class), 403);

        $query = Learner::query()->with(['status', 'guardians'])->orderBy('legal_name');

        if ($request->filled('q')) {
            $query->search($request->string('q')->toString());
        }

        if ($request->filled('status_id')) {
            $query->where('status_id', $request->integer('status_id'));
        }

        // A teacher sees the learners in the batches they teach, and no others.
        if (! $request->user()->can('learners.update') && ! $request->user()->scope_all_branches) {
            $query->whereHas('enrollments.batch.teachers', fn ($q) => $q->whereKey($request->user()->getKey()));
        }

        return LearnerResource::collection($query->paginate(25));
    }

    /**
     * Create a learner, optionally with a guardian and an immediate enrollment.
     *
     * Possible duplicates are returned as a 409 with the matches rather than being created
     * silently or refused outright. Two children in one family genuinely can share a name, so the
     * decision belongs to the person at the desk — but they should be shown what already exists
     * before they make it.
     */
    public function store(StoreLearnerRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $request->boolean('force')) {
            $matches = $this->duplicates->check(
                $data['legal_name'],
                $data['guardian']['email'] ?? null,
                $data['guardian']['phone'] ?? null,
                isset($data['date_of_birth']) ? new \DateTimeImmutable($data['date_of_birth']) : null,
            );

            if ($matches->isNotEmpty()) {
                return response()->json([
                    'message' => 'This may already be someone you have on file.',
                    'data' => [
                        'possible_duplicates' => $matches->map(fn (DuplicateCandidate $c) => $c->toArray()),
                        'to_continue' => 'Send the same request again with "force": true.',
                    ],
                ], 409);
            }
        }

        $learner = DB::transaction(function () use ($data): Learner {
            $learner = $this->learners->create(collect($data)->except(['guardian', 'batch_id', 'force'])->all());

            if (! empty($data['guardian'])) {
                $this->guardians->attachOrCreate(
                    $learner,
                    collect($data['guardian'])->except('receives_reports')->all(),
                    isPrimary: true,
                    receivesReports: $data['guardian']['receives_reports'] ?? true,
                );
            }

            if (! empty($data['batch_id'])) {
                $this->enrollments->enroll($learner, Batch::query()->findOrFail($data['batch_id']));
            }

            return $learner;
        });

        return (new LearnerResource($learner->load(['status', 'guardians', 'enrollments.status'])))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Learner $learner): LearnerResource
    {
        abort_unless($request->user()->can('view', $learner), 403);

        return new LearnerResource(
            $learner->load(['status', 'guardians.relationType', 'enrollments.status', 'enrollments.batch'])
        );
    }

    public function update(UpdateLearnerRequest $request, Learner $learner): LearnerResource
    {
        return new LearnerResource(
            $this->learners->update($learner, $request->validated())->load(['status', 'guardians'])
        );
    }

    public function destroy(Request $request, Learner $learner): JsonResponse
    {
        abort_unless($request->user()->can('archive', $learner), 403);

        // Archiving, not deleting: the reports already sent home were built on this history.
        $learner->delete();

        return response()->json([
            'data' => ['message' => $learner->displayName().' archived. Every record is kept.'],
        ]);
    }
}
