<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\TrialService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

final class InvitationController
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly TrialService $trials,
        private readonly TenantContext $tenancy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);

        return response()->json([
            'data' => [
                'invitations' => Invitation::query()->with('inviter')->latest('id')->get()
                    ->map(fn (Invitation $i) => [
                        'id' => $i->getKey(),
                        'email' => $i->email,
                        'role' => $i->role_name,
                        'status' => $i->status(),
                        'invited_by' => $i->inviter?->name,
                        'expires_on' => $i->expires_at->toDateString(),
                        'times_sent' => $i->send_count,
                    ]),
                // What this person is allowed to hand out, so the form never offers a role that
                // will be refused on submit.
                'roles_you_can_grant' => $this->grantableRoles($request->user()),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'role_name' => ['required', 'string', 'max:120'],
            'scope_all_branches' => ['boolean'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', TenantRule::exists('branches')],
        ]);

        $invitation = $this->invitations->invite($request->user(), $validated);

        return response()->json([
            'data' => [
                'id' => $invitation->getKey(),
                'email' => $invitation->email,
                'expires_on' => $invitation->expires_at->toDateString(),
                // Stated because it is the whole point of how invitations work here.
                'message' => 'Invitation sent. It grants nothing until they accept it, and expires in '
                    .config('signup.invitation_days', 7).' days.',
            ],
        ], 201);
    }

    public function resend(Request $request, Invitation $invitation): JsonResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);

        $this->invitations->resend($invitation);

        return response()->json(['data' => ['message' => 'Sent again with a fresh link.']]);
    }

    public function revoke(Request $request, Invitation $invitation): JsonResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);

        $this->invitations->revoke($invitation);

        return response()->json(['data' => ['message' => 'Withdrawn. The link no longer works.']]);
    }

    /** Trial state, for the banner. Readable by anyone who can see the dashboard. */
    public function trial(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->trials->status($this->tenancy->require())]);
    }

    /** @return array<int, string> */
    private function grantableRoles(User $user): array
    {
        $owner = (string) config('permissions.owner_role', 'Owner');

        if ($user->isOwner()) {
            return Role::query()->pluck('name')->all();
        }

        $held = $user->getAllPermissions()->pluck('name');

        return Role::query()->with('permissions')->get()
            ->reject(fn (Role $role) => strcasecmp($role->name, $owner) === 0)
            ->filter(fn (Role $role) => $role->permissions->pluck('name')->diff($held)->isEmpty())
            ->pluck('name')
            ->values()
            ->all();
    }
}
