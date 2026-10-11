<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreClosureRequest;
use App\Http\Resources\ClosureResource;
use App\Models\Branch;
use App\Models\Holiday;
use App\Services\ClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ClosureController
{
    public function __construct(private readonly ClosureService $closures) {}

    /** The days this branch is closed. From today on unless asked for a stretch of dates. */
    public function index(Request $request, Branch $branch): AnonymousResourceCollection
    {
        abort_unless($request->user()->can('viewAny', Holiday::class), 403);
        abort_unless($request->user()->can('view', $branch), 403);

        $closures = Holiday::query()
            ->where('branch_id', $branch->getKey())
            ->when($request->filled('from'), fn ($query) => $query->whereDate('date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('date', '<=', $request->date('to')))
            ->when(
                ! $request->filled('from') && ! $request->filled('to'),
                // A closure in the past changes nothing from here on, so the default is what is
                // still ahead.
                fn ($query) => $query->whereDate('date', '>=', now()->toDateString()),
            )
            ->orderBy('date')
            ->get();

        return ClosureResource::collection($closures);
    }

    public function store(StoreClosureRequest $request, Branch $branch): JsonResponse
    {
        abort_unless($request->user()->can('view', $branch), 403);

        $closure = $this->closures->create($branch, $request->validated());
        $standing = $this->closures->sessionsOn($closure)->count();

        $cancelled = $request->boolean('cancel_sessions') && $closure->blocks_sessions
            ? $this->closures->cancelSessions($closure)
            : 0;

        return (new ClosureResource($closure))
            ->additional(['meta' => [
                // What the person needs to decide next: generation will skip the day from now on,
                // but sessions already on the calendar stay until someone cancels them.
                'sessions_on_the_day' => $standing,
                'sessions_cancelled' => $cancelled,
                'message' => $this->message($standing, $cancelled, $closure),
            ]])
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, Holiday $closure): JsonResponse
    {
        abort_unless($request->user()->can('delete', $closure), 403);

        $this->closures->remove($closure);

        return response()->json([
            'data' => ['message' => 'Closure removed. Sessions cancelled for it stay cancelled.'],
        ]);
    }

    private function message(int $standing, int $cancelled, Holiday $closure): string
    {
        if ($cancelled > 0) {
            return sprintf(
                'Added. %d %s that day %s cancelled.',
                $cancelled,
                $cancelled === 1 ? 'session' : 'sessions',
                $cancelled === 1 ? 'was' : 'were',
            );
        }

        if ($standing > 0) {
            return sprintf(
                'Added. %d %s still on the calendar that day; cancel them if the closure applies to them.',
                $standing,
                $standing === 1 ? 'session is' : 'sessions are',
            );
        }

        return $closure->blocks_sessions
            ? 'Added. Sessions will not be generated for that day.'
            : 'Added as a note; sessions are still generated for that day.';
    }
}
