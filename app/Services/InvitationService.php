<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Bringing colleagues in.
 *
 * The rule that matters most here is the escalation guard: nobody may invite someone into a role
 * that holds permissions the inviter does not hold themselves. Without it, "manage users" quietly
 * becomes "become the owner", which is the classic way a carefully composed permission system
 * turns out to have had one door open all along (SL-SEC-004 §5.3).
 */
final class InvitationService
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function invite(User $inviter, array $input): Invitation
    {
        $email = strtolower(trim($input['email']));
        $roleName = $input['role_name'];

        $this->guardEscalation($inviter, $roleName);
        $this->guardAlreadyStaff($email);
        $this->guardBranchScope($inviter, $input);

        return DB::transaction(function () use ($inviter, $input, $email, $roleName): Invitation {
            $existing = Invitation::query()->where('email', $email)->first();
            $token = Str::random(48);

            $attributes = [
                'email' => $email,
                'name' => $input['name'] ?? null,
                'token_hash' => hash('sha256', $token),
                'role_name' => $roleName,
                'scope_all_branches' => (bool) ($input['scope_all_branches'] ?? false),
                'branch_ids' => $input['branch_ids'] ?? null,
                'invited_by' => $inviter->getKey(),
                'expires_at' => now()->addDays((int) config('signup.invitation_days', 7)),
                'accepted_at' => null,
                'revoked_at' => null,
            ];

            if ($existing !== null) {
                // Re-inviting replaces the previous token rather than leaving two working links
                // for the same person.
                $existing->update($attributes + ['send_count' => $existing->send_count + 1]);
                $invitation = $existing->refresh();
            } else {
                $invitation = Invitation::query()->create($attributes);
            }

            $this->send($invitation, $token);

            return $invitation;
        });
    }

    public function resend(Invitation $invitation): Invitation
    {
        if (! $invitation->isOpen()) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation has already been '.$invitation->status().'.',
            ]);
        }

        $token = Str::random(48);

        $invitation->update([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('signup.invitation_days', 7)),
            'send_count' => $invitation->send_count + 1,
        ]);

        $this->send($invitation, $token);

        return $invitation->refresh();
    }

    public function revoke(Invitation $invitation): Invitation
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation has already been accepted. Deactivate the person instead.',
            ]);
        }

        // Clearing the hash matters as much as the timestamp: a revoked invitation whose token
        // still works is not revoked.
        $invitation->update(['revoked_at' => now(), 'token_hash' => hash('sha256', Str::random(48))]);

        return $invitation->refresh();
    }

    /** What the acceptance page shows, without requiring a session. */
    public function preview(string $token): Invitation
    {
        $invitation = $this->findByToken($token);

        if (! $invitation->isOpen()) {
            throw ValidationException::withMessages([
                'token' => match ($invitation->status()) {
                    'accepted' => 'This invitation has already been used. Try signing in.',
                    'revoked' => 'This invitation was withdrawn. Ask whoever invited you to send another.',
                    default => 'This invitation has expired. Ask whoever invited you to send another.',
                },
            ]);
        }

        return $invitation;
    }

    /** @param array<string, mixed> $input */
    public function accept(string $token, array $input): User
    {
        $invitation = $this->preview($token);

        $tenant = $this->tenancy->withoutScoping(
            fn () => Tenant::query()->findOrFail($invitation->tenant_id)
        );

        return $this->tenancy->runAs($tenant, fn () => DB::transaction(function () use ($invitation, $input): User {
            $user = User::query()->create([
                'name' => $input['name'] ?? $invitation->name ?? $invitation->email,
                'email' => $invitation->email,
                'password' => Hash::make($input['password']),
                'is_active' => true,
                'scope_all_branches' => $invitation->scope_all_branches,
                'locale' => $input['locale'] ?? 'en',
                'timezone' => $input['timezone'] ?? null,
                // Accepting an invitation sent to an address is itself proof of the address.
                'email_verified_at' => now(),
            ]);

            $user->syncRoles([$invitation->role_name]);

            if (! $invitation->scope_all_branches && $invitation->branch_ids !== null) {
                $user->branches()->sync($this->pivotFor($invitation->branch_ids, $user->tenant_id));
            }

            $invitation->update([
                'accepted_at' => now(),
                'accepted_user_id' => $user->getKey(),
                'token_hash' => hash('sha256', Str::random(48)),
            ]);

            return $user->fresh();
        }));
    }

    /**
     * Nobody may invite into a role holding permissions they do not hold themselves.
     */
    private function guardEscalation(User $inviter, string $roleName): void
    {
        if ($inviter->isOwner()) {
            return;
        }

        $role = Role::query()->where('name', $roleName)->first();

        if ($role === null) {
            throw ValidationException::withMessages([
                'role_name' => "There is no role called [{$roleName}] in this account.",
            ]);
        }

        if (strcasecmp($roleName, (string) config('permissions.owner_role', 'Owner')) === 0) {
            throw ValidationException::withMessages([
                'role_name' => 'Only the account owner can invite another owner.',
            ]);
        }

        $granted = $role->permissions->pluck('name');
        $held = $inviter->getAllPermissions()->pluck('name');
        $excess = $granted->diff($held);

        if ($excess->isNotEmpty()) {
            throw ValidationException::withMessages([
                'role_name' => sprintf(
                    'You cannot invite someone into a role with permissions you do not have yourself: %s.',
                    $excess->take(3)->implode(', '),
                ),
            ]);
        }
    }

    private function guardAlreadyStaff(string $email): void
    {
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'That person already has an account here.',
            ]);
        }
    }

    /** @param array<string, mixed> $input */
    private function guardBranchScope(User $inviter, array $input): void
    {
        if ($inviter->isOwner() || ($input['scope_all_branches'] ?? false) === false) {
            return;
        }

        if (! $inviter->scope_all_branches) {
            // Someone limited to one location cannot hand out access to every location.
            throw ValidationException::withMessages([
                'scope_all_branches' => 'You can only invite people to the locations you can reach yourself.',
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function pivotFor(array $branchIds, int $tenantId): array
    {
        $valid = Branch::query()->whereIn('id', $branchIds)->pluck('id');

        return $valid->mapWithKeys(fn (int $id) => [$id => ['tenant_id' => $tenantId]])->all();
    }

    private function findByToken(string $token): Invitation
    {
        $invitation = $this->tenancy->withoutScoping(
            fn () => Invitation::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->first()
        );

        return $invitation ?? throw ValidationException::withMessages([
            'token' => 'That invitation link is not valid.',
        ]);
    }

    private function send(Invitation $invitation, string $token): void
    {
        $tenant = $this->tenancy->withoutScoping(
            fn () => Tenant::query()->find($invitation->tenant_id)
        );

        Notification::route('mail', $invitation->email)
            ->notify(new StaffInvitationNotification($invitation, $token, $tenant?->name ?? 'your academy'));
    }
}
