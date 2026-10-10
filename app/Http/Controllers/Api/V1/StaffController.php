<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\SessionType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The lists a setup screen chooses from: who works here, and what kinds of session there are. */
final class StaffController
{
    /** Everyone on this academy's team, for assigning teachers and managing people. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('batches.manage') || $user->can('users.manage') || $user->isOwner(), 403);

        return response()->json([
            'data' => User::query()->with('roles')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->is_active,
                'roles' => $u->getRoleNames()->values(),
            ])->values(),
        ]);
    }

    public function sessionTypes(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('learners.view') || $request->user()->can('batches.manage') || $request->user()->isOwner(), 403);

        return response()->json([
            'data' => SessionType::query()->orderBy('sort')->orderBy('id')->get()->map(fn (SessionType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'code' => $t->code,
                // Shown when choosing: a make-up session does not count toward the attendance figure.
                'counts_in_attendance' => (bool) $t->counts_in_attendance,
            ])->values(),
        ]);
    }
}
