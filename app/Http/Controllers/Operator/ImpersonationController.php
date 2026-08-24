<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operator;

use App\Models\Impersonation;
use App\Models\Tenant;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ImpersonationController
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function start(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'minutes' => ['nullable', 'integer', 'between:5,60'],
        ]);

        $result = $this->impersonation->start(
            $request->user('operator'),
            $tenant,
            $validated['reason'],
            $validated['minutes'] ?? 15,
        );

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'expires_at_utc' => $result['impersonation']->expires_at->toIso8601String(),
                'acting_as' => $result['impersonation']->user->name,
                // Said plainly to whoever is about to use it.
                'notice' => 'This session is recorded in the customer\'s own activity log, with your '
                    .'name and the reason you gave. You cannot change billing or delete anything.',
            ],
        ], 201);
    }

    public function end(Request $request, Impersonation $impersonation): JsonResponse
    {
        abort_unless(
            $impersonation->operator_id === $request->user('operator')->getKey(),
            403,
            'That session belongs to another operator.',
        );

        $ended = $this->impersonation->end($impersonation);

        return response()->json([
            'data' => ['minutes_used' => $ended->minutesUsed(), 'message' => 'Session ended.'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Impersonation::query()->with(['operator', 'tenant'])->latest('started_at')->take(100);

        return response()->json([
            'data' => $query->get()->map(fn (Impersonation $i) => [
                'id' => $i->getKey(),
                'operator' => $i->operator->name,
                'tenant' => $i->tenant?->name,
                'reason' => $i->reason,
                'started_at_utc' => $i->started_at->toIso8601String(),
                'ended_at_utc' => $i->ended_at?->toIso8601String(),
                'minutes' => $i->minutesUsed(),
                'active' => $i->isActive(),
            ]),
        ]);
    }
}
