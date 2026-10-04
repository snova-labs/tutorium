<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changing what an account is on.
 *
 * There is deliberately **no proration arithmetic** here. The metric is the peak active-learner
 * count, so there is no flat fee to apportion. Instead:
 *
 *  - **Upgrading** applies immediately. The invoice for the period is split at the change and each
 *    part is metered against its own rate (InvoiceComposer), so the customer can check both lines.
 *  - **Downgrading** takes effect at the end of the period already paid for. Nobody loses access
 *    they have paid for, and nobody is surprised.
 *
 * Every change is recorded as a PlanChange, which is what the invoice reads to find the split
 * (SL-BIL-006 §4).
 */
final class SubscriptionService
{
    /** The limits a downgrade is checked against, and what each one counts. */
    private const MEASURED_LIMITS = [
        'max_brands' => 'brands',
        'max_branches' => 'branches',
        'max_staff' => 'staff',
    ];

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly EntitlementService $entitlements,
    ) {}

    public function subscribe(Tenant $tenant, Plan $plan, string $provider = 'manual'): Subscription
    {
        return $this->tenancy->runAs($tenant, fn () => Subscription::query()->create([
            'plan_id' => $plan->getKey(),
            'provider' => $provider,
            'status' => Subscription::ACTIVE,
            'current_period_start' => now()->startOfMonth()->toDateString(),
            'current_period_end' => now()->endOfMonth()->toDateString(),
        ]));
    }

    /**
     * Move to another plan.
     *
     * A downgrade the account no longer fits — more brands than the new plan allows, say — is
     * refused until the customer acknowledges it. Nothing is ever deleted either way.
     *
     * @return array{subscription: Subscription, effective: string, message: string}
     */
    public function changePlan(Tenant $tenant, Plan $target, bool $acknowledgeLosses = false): array
    {
        return $this->tenancy->runAs($tenant, function () use ($tenant, $target, $acknowledgeLosses): array {
            $subscription = Subscription::query()->with('plan')->latest('id')->firstOr(
                fn () => throw ValidationException::withMessages([
                    'subscription' => 'This account has no subscription to change.',
                ]),
            );

            if ($subscription->plan_id === $target->getKey()) {
                throw ValidationException::withMessages([
                    'plan' => 'The account is already on that plan.',
                ]);
            }

            if ($target->unit_price_minor >= $subscription->plan->unit_price_minor) {
                return $this->upgrade($subscription, $target);
            }

            $overages = $this->overages($target);

            if ($overages !== [] && ! $acknowledgeLosses) {
                throw ValidationException::withMessages([
                    'plan' => sprintf(
                        '%s would not fit this account: %s. Nothing will be deleted, but you will not be '
                        .'able to add more until you are within the limits. Confirm to go ahead.',
                        $target->name,
                        implode('; ', $overages),
                    ),
                ]);
            }

            return $this->scheduleDowngrade($tenant, $subscription, $target);
        });
    }

    /** Applies every scheduled plan change whose date has arrived. Runs daily. */
    public function applyScheduledChanges(): int
    {
        $applied = 0;

        $tenants = $this->tenancy->withoutScoping(fn () => Tenant::query()->get());

        foreach ($tenants as $tenant) {
            $this->tenancy->runAs($tenant, function () use (&$applied): void {
                $due = PlanChange::query()
                    ->whereNull('applied_at')
                    ->whereDate('effective_on', '<=', now()->toDateString())
                    ->orderBy('effective_on')
                    ->get();

                foreach ($due as $change) {
                    DB::transaction(function () use ($change): void {
                        Subscription::query()->whereKey($change->subscription_id)->first()?->update([
                            'plan_id' => $change->to_plan_id,
                            'pending_plan_id' => null,
                            'pending_plan_starts_on' => null,
                            'current_period_start' => $change->effective_on->toDateString(),
                            'current_period_end' => $change->effective_on->endOfMonth()->toDateString(),
                        ]);

                        $change->update(['applied_at' => now()]);
                    });

                    $applied++;
                }
            });
        }

        $this->entitlements->forget();

        return $applied;
    }

    /** @deprecated Use applyScheduledChanges(); kept for the scheduled command. */
    public function applyPendingChanges(): int
    {
        return $this->applyScheduledChanges();
    }

    /**
     * Stop the subscription. By default service continues to the end of the period already paid
     * for, because an academy mid-term should not lose access because someone clicked cancel.
     */
    public function cancel(Tenant $tenant, ?string $reason = null, bool $immediately = false): Subscription
    {
        return $this->tenancy->runAs($tenant, function () use ($reason, $immediately): Subscription {
            $subscription = Subscription::query()->latest('id')->firstOrFail();

            $subscription->update(($immediately
                ? ['status' => Subscription::CANCELLED, 'cancelled_at' => now()]
                : ['cancel_at_period_end' => true]) + ['cancellation_reason' => $reason]);

            return $subscription->refresh();
        });
    }

    /** Undo a cancellation that has not yet taken effect. */
    public function resume(Tenant $tenant): Subscription
    {
        return $this->tenancy->runAs($tenant, function (): Subscription {
            $subscription = Subscription::query()->latest('id')->firstOrFail();

            if ($subscription->status === Subscription::CANCELLED) {
                throw ValidationException::withMessages([
                    'subscription' => 'This subscription has already ended. Start a new one instead.',
                ]);
            }

            $subscription->update(['cancel_at_period_end' => false, 'cancellation_reason' => null]);

            return $subscription->refresh();
        });
    }

    /** @return array{subscription: Subscription, effective: string, message: string} */
    private function upgrade(Subscription $subscription, Plan $target): array
    {
        return DB::transaction(function () use ($subscription, $target): array {
            // An upgrade supersedes any downgrade that was waiting.
            PlanChange::query()->where('subscription_id', $subscription->getKey())->whereNull('applied_at')->delete();

            PlanChange::query()->create([
                'subscription_id' => $subscription->getKey(),
                'from_plan_id' => $subscription->plan_id,
                'to_plan_id' => $target->getKey(),
                'direction' => PlanChange::UPGRADE,
                'effective_on' => now()->toDateString(),
                'applied_at' => now(),
            ]);

            $subscription->update([
                'plan_id' => $target->getKey(),
                'pending_plan_id' => null,
                'pending_plan_starts_on' => null,
            ]);

            $this->entitlements->forget();

            return [
                'subscription' => $subscription->refresh(),
                'effective' => now()->toDateString(),
                'message' => sprintf(
                    'You are on %s now. This period\'s invoice is split at today: your old rate up to '
                    .'yesterday, the new rate from today.',
                    $target->name,
                ),
            ];
        });
    }

    /** @return array{subscription: Subscription, effective: string, message: string} */
    private function scheduleDowngrade(Tenant $tenant, Subscription $subscription, Plan $target): array
    {
        $effective = $subscription->current_period_end->addDay()->toDateString();
        $losses = $this->whatWouldBeLost($tenant, $target);

        DB::transaction(function () use ($subscription, $target, $effective): void {
            // Only one change waits at a time; choosing again replaces it.
            PlanChange::query()->where('subscription_id', $subscription->getKey())->whereNull('applied_at')->delete();

            PlanChange::query()->create([
                'subscription_id' => $subscription->getKey(),
                'from_plan_id' => $subscription->plan_id,
                'to_plan_id' => $target->getKey(),
                'direction' => PlanChange::DOWNGRADE,
                'effective_on' => $effective,
            ]);

            $subscription->update([
                'pending_plan_id' => $target->getKey(),
                'pending_plan_starts_on' => $effective,
            ]);
        });

        return [
            'subscription' => $subscription->refresh(),
            'effective' => $effective,
            // Told before it happens, not discovered afterwards.
            'message' => $losses === []
                ? sprintf('You will move to %s on %s. Nothing changes until then.', $target->name, $effective)
                : sprintf('You will move to %s on %s. At that point: %s', $target->name, $effective, implode(' ', $losses)),
        ];
    }

    /**
     * Where the account already holds more than the target plan's hard limits allow.
     *
     * @return array<int, string>
     */
    private function overages(Plan $target): array
    {
        $overages = [];

        foreach ($target->features as $feature) {
            $noun = self::MEASURED_LIMITS[$feature->feature_key] ?? null;
            $limit = $feature->value['value'] ?? null;

            if ($noun === null || $feature->enforcement !== PlanFeature::HARD || ! is_int($limit)) {
                continue;
            }

            $current = $this->usage($feature->feature_key);

            if ($current > $limit) {
                $overages[] = sprintf('you have %d %s and it allows %d', $current, $noun, $limit);
            }
        }

        return $overages;
    }

    private function usage(string $key): int
    {
        return match ($key) {
            'max_brands' => Brand::query()->count(),
            'max_branches' => Branch::query()->count(),
            'max_staff' => User::query()->where('is_active', true)->count(),
            default => 0,
        };
    }

    /**
     * What a downgrade would cost this particular account, in plain terms.
     *
     * @return array<int, string>
     */
    private function whatWouldBeLost(Tenant $tenant, Plan $target): array
    {
        $losses = [];
        $current = $this->entitlements->all($tenant);

        foreach ($target->features as $feature) {
            $key = $feature->feature_key;
            $newValue = $feature->value['value'] ?? $feature->value;
            $oldValue = $current[$key]?->value ?? null;

            if (is_bool($oldValue) && $oldValue === true && $newValue === false) {
                $losses[] = str_replace('_', ' ', $key).' will no longer be available.';

                continue;
            }

            if (is_int($oldValue) && is_int($newValue) && $newValue < $oldValue) {
                $losses[] = sprintf('%s drops from %d to %d.', str_replace('_', ' ', $key), $oldValue, $newValue);
            }
        }

        return $losses;
    }
}
