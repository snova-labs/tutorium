<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Models\Tenant;
use App\Services\InvitationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accepting an invitation, before the person has an account to authenticate with.
 */
final class InvitationController
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly TenantContext $tenancy,
    ) {}

    /** What the acceptance page shows. Deliberately minimal — this endpoint is unauthenticated. */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->invitations->preview($token);

        $tenant = $this->tenancy->withoutScoping(
            fn () => Tenant::query()->find($invitation->tenant_id)
        );

        return response()->json([
            'data' => [
                'academy' => $tenant?->name,
                'role' => $invitation->role_name,
                'email' => $invitation->email,
                'name' => $invitation->name,
                'expires_on' => $invitation->expires_at->toDateString(),
            ],
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
            'timezone' => ['nullable', 'timezone:all'],
            'locale' => ['nullable', 'string', 'max:12'],
        ], ['password.min' => 'Use at least 12 characters. A short phrase works well.']);

        $user = $this->invitations->accept($token, $validated);

        return response()->json([
            'data' => [
                'email' => $user->email,
                'message' => 'You are in. Sign in with your email address and the password you just set.',
            ],
        ], 201);
    }
}
