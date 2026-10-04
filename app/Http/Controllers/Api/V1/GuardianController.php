<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\GuardianResource;
use App\Models\Guardian;
use App\Models\Learner;
use App\Services\GuardianService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

final class GuardianController
{
    public function __construct(private readonly GuardianService $guardians) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('viewAny', Guardian::class), 403);

        $query = Guardian::query()->with('relationType')->withCount('learners')->orderBy('name');

        if ($request->filled('q')) {
            $term = '%'.$request->string('q')->toString().'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }

        return GuardianResource::collection($query->paginate(25));
    }

    /** Link a guardian to a learner, reusing an existing record where the contact matches. */
    public function attach(Request $request, Learner $learner): JsonResponse
    {
        abort_unless($request->user()->can('create', Guardian::class), 403);

        $validated = $request->validate([
            'guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'name' => ['required_without:guardian_id', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'relation_type_id' => ['nullable', 'integer', 'exists:relation_types,id'],
            'is_primary' => ['boolean'],
            'receives_reports' => ['boolean'],
        ]);

        if (! empty($validated['guardian_id'])) {
            $guardian = Guardian::query()->findOrFail($validated['guardian_id']);
            $this->guardians->link(
                $learner,
                $guardian,
                $request->boolean('is_primary'),
                $request->boolean('receives_reports', true),
            );
        } else {
            $guardian = $this->guardians->attachOrCreate(
                $learner,
                Arr::only($validated, ['name', 'email', 'phone', 'relation_type_id']),
                $request->boolean('is_primary'),
                $request->boolean('receives_reports', true),
            );
        }

        return (new GuardianResource($guardian->load('relationType')))->response()->setStatusCode(201);
    }

    public function detach(Request $request, Learner $learner, Guardian $guardian): JsonResponse
    {
        abort_unless($request->user()->can('delete', $guardian), 403);

        $this->guardians->unlink($learner, $guardian);

        return response()->json(['data' => ['message' => 'Guardian unlinked.']]);
    }

    public function setRecipient(Request $request, Learner $learner, Guardian $guardian): JsonResponse
    {
        abort_unless($request->user()->can('update', $guardian), 403);

        $validated = $request->validate(['receives_reports' => ['required', 'boolean']]);

        $this->guardians->setReportRecipient($learner, $guardian, $validated['receives_reports']);

        return response()->json([
            'data' => [
                'message' => $validated['receives_reports']
                    ? $guardian->name.' will receive reports for '.$learner->displayName().'.'
                    : $guardian->name.' will no longer receive reports for '.$learner->displayName().'.',
            ],
        ]);
    }
}
