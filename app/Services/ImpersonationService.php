<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Impersonation;
use App\Models\Operator;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;

/**
 * Support access to a customer's account.
 *
 * Three properties make this defensible rather than alarming: a reason is required, the session
 * expires on its own, and the whole thing is written to **the tenant's own activity log** — the
 * same log they read, not a separate one we keep. A customer should be able to see when we looked
 * without asking us (FR-OPS-2, FR-AUD-3).
 */
final class ImpersonationService
{
    /** Support work is short. Anything longer is a conversation, not a look. */
    private const MAX_MINUTES = 60;

    public function __construct(private readonly TenantContext $tenancy) {}

    /** @return array{impersonation: Impersonation, token: string} */
    public function start(Operator $operator, Tenant $tenant, string $reason, int $minutes = 15): array
    {
        if (! $operator->canSignIn()) {
            throw ValidationException::withMessages([
                'operator' => 'Two-factor authentication must be set up before you can access a customer account.',
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Say why you need access. This text is copied into the customer\'s own activity log.',
            ]);
        }

        $minutes = min(max($minutes, 5), self::MAX_MINUTES);

        $existing = Impersonation::query()
            ->where('operator_id', $operator->getKey())
            ->whereNull('ended_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'impersonation' => 'You already have an open session. End it before starting another.',
            ]);
        }

        $user = $this->ownerOf($tenant);

        return DB::transaction(function () use ($operator, $tenant, $user, $reason, $minutes): array {
            $impersonation = Impersonation::query()->create([
                'operator_id' => $operator->getKey(),
                'tenant_id' => $tenant->getKey(),
                'user_id' => $user->getKey(),
                'reason' => $reason,
                'started_at' => now(),
                'expires_at' => now()->addMinutes($minutes),
                'ip' => Request::ip(),
            ]);

            $this->writeToTenantLog($tenant, $operator, 'support_access_started', $reason, [
                'expires_at_utc' => $impersonation->expires_at->toIso8601String(),
                'minutes' => $minutes,
                'acting_as' => $user->name,
            ]);

            // A token, not a session: it expires on its own, carries an ability that marks every
            // request as support access, and can be revoked without touching the operator.
            $token = $user->createToken(
                'support-access-'.$impersonation->getKey(),
                ['impersonate'],
                $impersonation->expires_at,
            )->plainTextToken;

            return ['impersonation' => $impersonation, 'token' => $token];
        });
    }

    public function end(Impersonation $impersonation): Impersonation
    {
        if ($impersonation->ended_at !== null) {
            return $impersonation;
        }

        return DB::transaction(function () use ($impersonation): Impersonation {
            $impersonation->update(['ended_at' => now()]);

            // Called from the operator side and the scheduled sweep, where no tenant is bound; the
            // support user and their token live in the tenant being accessed.
            $this->tenancy->runAs($impersonation->tenant, fn () => $impersonation->user()->first()?->tokens()
                ->where('name', 'support-access-'.$impersonation->getKey())
                ->delete());

            $this->writeToTenantLog(
                $impersonation->tenant,
                $impersonation->operator,
                'support_access_ended',
                $impersonation->reason,
                ['minutes_used' => $impersonation->minutesUsed()],
            );

            return $impersonation->refresh();
        });
    }

    /**
     * Everything a customer can see about our access to their account.
     *
     * @return array<int, array{operator: string, reason: string, started_at_utc: string, ended_at_utc: string|null, minutes: int, active: bool}>
     */
    public function historyFor(Tenant $tenant): array
    {
        return Impersonation::query()
            ->with('operator')
            ->where('tenant_id', $tenant->getKey())
            ->latest('started_at')
            ->get()
            ->map(fn (Impersonation $i) => [
                'operator' => $i->operator->name,
                'reason' => $i->reason,
                'started_at_utc' => $i->started_at->toIso8601String(),
                'ended_at_utc' => $i->ended_at?->toIso8601String(),
                'minutes' => $i->minutesUsed(),
                'active' => $i->isActive(),
            ])
            ->all();
    }

    /** Closes anything that ran past its expiry — belt and braces alongside token expiry. */
    public function closeExpired(): int
    {
        $expired = Impersonation::query()
            ->whereNull('ended_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $impersonation) {
            $this->end($impersonation);
        }

        return $expired->count();
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function writeToTenantLog(Tenant $tenant, Operator $operator, string $action, string $reason, array $detail): void
    {
        $this->tenancy->withoutScoping(function () use ($tenant, $operator, $action, $reason, $detail): void {
            AuditLog::query()->create([
                'tenant_id' => $tenant->getKey(),
                'actor_type' => AuditLog::ACTOR_OPERATOR,
                'actor_id' => $operator->getKey(),
                'actor_name' => $operator->name.' (support)',
                'module' => 'Support access',
                'action' => $action,
                'target_label' => $tenant->name,
                'after' => ['reason' => $reason] + $detail,
                'ip' => Request::ip(),
                'occurred_at' => now(),
            ]);
        });
    }

    private function ownerOf(Tenant $tenant): User
    {
        $user = $this->tenancy->runAs(
            $tenant,
            fn () => User::query()->where('is_active', true)->orderBy('id')->first(),
        );

        return $user ?? throw ValidationException::withMessages([
            'tenant' => 'This account has no active user to act as.',
        ]);
    }
}
