<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BillingProfile;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TrialEndingNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The fortnight between signing up and deciding.
 *
 * What happens at the end is the whole design: the account goes read-only, exactly as a suspended
 * one does. Everything entered during the trial is still there, still readable, still exportable,
 * and comes back untouched the moment someone pays. Nobody re-types a roster because they took
 * three weeks to get budget approval (SL-PRD-000 §6).
 */
final class TrialService
{
    /** Days remaining at which a reminder goes out. */
    private const REMIND_AT = [7, 3, 1];

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly OnboardingChecklist $checklist,
    ) {}

    /** @return array<string, mixed> */
    public function status(Tenant $tenant): array
    {
        if ($tenant->status !== Tenant::STATUS_TRIAL || $tenant->trial_ends_at === null) {
            return ['in_trial' => false];
        }

        $daysLeft = (int) ceil(now()->diffInDays($tenant->trial_ends_at, false));
        $onboarding = $this->checklist->for($tenant);

        return [
            'in_trial' => true,
            'ends_on' => $tenant->trial_ends_at->toDateString(),
            'days_left' => max(0, $daysLeft),
            'ready_to_teach' => $onboarding['can_record_attendance'],
            // Said before it happens, and said accurately.
            'what_happens_at_the_end' => [
                'Your account becomes read-only.',
                'Everything you have entered stays exactly as it is.',
                'You can still read, download and export all of it.',
                'Choosing a plan and how to pay restores full access immediately — nothing is rebuilt.',
            ],
        ];
    }

    /**
     * Send whichever reminder is due, at most once each.
     *
     * @return array{sent: int}
     */
    public function sendDueReminders(): array
    {
        $sent = 0;

        foreach ($this->trialTenants() as $tenant) {
            $daysLeft = (int) ceil(now()->diffInDays($tenant->trial_ends_at, false));
            $already = $tenant->trial_reminders_sent ?? [];

            foreach (self::REMIND_AT as $threshold) {
                if ($daysLeft > $threshold || in_array($threshold, $already, true)) {
                    continue;
                }

                $this->notifyOwner($tenant, $threshold, $daysLeft);

                $already[] = $threshold;
                $this->tenancy->withoutScoping(
                    fn () => $tenant->update(['trial_reminders_sent' => array_values(array_unique($already))]),
                );

                $sent++;

                break;
            }
        }

        return ['sent' => $sent];
    }

    /**
     * Move expired trials to read-only.
     *
     * @return array{expired: int}
     */
    public function expireDue(): array
    {
        $expired = 0;

        foreach ($this->trialTenants() as $tenant) {
            if ($tenant->trial_ends_at->isFuture()) {
                continue;
            }

            // Someone who has already added a payment method converts rather than expires, even if
            // the trial date has passed — the intent is unambiguous.
            if ($this->hasPaidSubscription($tenant)) {
                $this->convert($tenant);

                continue;
            }

            $this->tenancy->withoutScoping(function () use ($tenant): void {
                $tenant->update([
                    'status' => Tenant::STATUS_SUSPENDED,
                    'trial_expired_at' => now(),
                    'suspended_at' => now(),
                ]);

                AuditLog::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'actor_type' => AuditLog::ACTOR_SYSTEM,
                    'actor_name' => 'Billing',
                    'module' => 'Account',
                    'action' => 'status_changed',
                    'target_label' => $tenant->name,
                    'before' => ['status' => Tenant::STATUS_TRIAL],
                    'after' => [
                        'status' => Tenant::STATUS_SUSPENDED,
                        'reason' => 'Trial ended. Everything is kept and remains exportable.',
                    ],
                    'occurred_at' => now(),
                ]);
            });

            $expired++;
        }

        return ['expired' => $expired];
    }

    /** A trial still running, or one that ended without anyone paying. */
    public function isConvertible(Tenant $tenant): bool
    {
        return $tenant->status === Tenant::STATUS_TRIAL
            || ($tenant->status === Tenant::STATUS_SUSPENDED && $tenant->trial_expired_at !== null);
    }

    /**
     * Choose the plan a trial becomes. The subscription waits as "trialing" until there is a way to
     * pay; nothing is charged and nothing changes for the account until then.
     */
    public function choosePlan(Tenant $tenant, Plan $plan, string $provider): Subscription
    {
        return $this->tenancy->runAs($tenant, function () use ($plan, $provider): Subscription {
            $subscription = Subscription::query()->latest('id')->first();

            $attributes = [
                'plan_id' => $plan->getKey(),
                'provider' => $provider,
                'status' => Subscription::TRIALING,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->endOfMonth()->toDateString(),
            ];

            if ($subscription === null || $subscription->status === Subscription::CANCELLED) {
                return Subscription::query()->create($attributes);
            }

            $subscription->update($attributes);

            return $subscription;
        });
    }

    /**
     * Convert a trial whose plan is chosen, once a way to pay exists (a card arriving by webhook,
     * or the customer choosing invoicing). False when this tenant is not waiting on that.
     */
    public function convertIfReady(Tenant $tenant): bool
    {
        if (! $this->isConvertible($tenant)) {
            return false;
        }

        $ready = $this->tenancy->runAs($tenant, function (): bool {
            $subscription = Subscription::query()->latest('id')->first();
            $profile = BillingProfile::query()->first();

            return $subscription !== null
                && $subscription->status !== Subscription::CANCELLED
                && $profile !== null
                && ($profile->prefers_invoicing || $profile->hasUsablePaymentMethod());
        });

        if ($ready) {
            $this->convert($tenant);
        }

        return $ready;
    }

    /** Turn a trial into a paying account. Nothing about the data changes. */
    public function convert(Tenant $tenant): Tenant
    {
        $before = $tenant->status;

        return DB::transaction(function () use ($tenant, $before): Tenant {
            $this->tenancy->withoutScoping(fn () => $tenant->update([
                'status' => Tenant::STATUS_ACTIVE,
                'trial_ends_at' => null,
                'suspended_at' => null,
                'trial_expired_at' => null,
            ]));

            $this->tenancy->runAs($tenant, function (): void {
                Subscription::query()->latest('id')->first()?->update([
                    'status' => Subscription::ACTIVE,
                ]);
            });

            $this->tenancy->withoutScoping(fn () => AuditLog::query()->create([
                'tenant_id' => $tenant->getKey(),
                'actor_type' => AuditLog::ACTOR_SYSTEM,
                'actor_name' => 'Billing',
                'module' => 'Account',
                'action' => 'status_changed',
                'target_label' => $tenant->name,
                'before' => ['status' => $before],
                'after' => ['status' => Tenant::STATUS_ACTIVE, 'reason' => 'Trial converted to a paying account.'],
                'occurred_at' => now(),
            ]));

            return $tenant->refresh();
        });
    }

    /** @return Collection<int, Tenant> */
    private function trialTenants(): Collection
    {
        return $this->tenancy->withoutScoping(
            fn () => Tenant::query()
                ->where('status', Tenant::STATUS_TRIAL)
                ->whereNotNull('trial_ends_at')
                ->get(),
        );
    }

    private function hasPaidSubscription(Tenant $tenant): bool
    {
        return $this->tenancy->runAs($tenant, function (): bool {
            $subscription = Subscription::query()->latest('id')->first();

            return $subscription !== null
                && in_array($subscription->status, [Subscription::TRIALING, Subscription::ACTIVE, Subscription::PAST_DUE], true)
                && BillingProfile::query()->first()?->hasPaymentMethod() === true;
        });
    }

    private function notifyOwner(Tenant $tenant, int $threshold, int $daysLeft): void
    {
        $this->tenancy->runAs($tenant, function () use ($tenant, $threshold, $daysLeft): void {
            $owner = User::query()->where('is_active', true)->orderBy('id')->first();

            if ($owner === null) {
                return;
            }

            $onboarding = $this->checklist->for($tenant);

            $owner->notify(new TrialEndingNotification(
                daysLeft: max(0, $daysLeft),
                threshold: $threshold,
                readyToTeach: $onboarding['can_record_attendance'],
                nextStep: collect($onboarding['steps'])->firstWhere('done', false)['title'] ?? null,
            ));
        });
    }
}
