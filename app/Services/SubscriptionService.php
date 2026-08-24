<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changing what an account is on.
 *
 * There is deliberately **no proration arithmetic** here, and that is a design decision rather than
 * an omission. The metric is already period-based — the peak active-learner count for the month —
 * so a mid-period plan change has no partial quantity to apportion. Instead:
 *
 *  - **Upgrading** grants the new plan's features immediately and charges the new rate from the
 *    next period. The customer gets a few days of the better plan free, which costs us very little
 *    and removes an entire category of billing dispute.
 *  - **Downgrading** takes effect at the end of the period already paid for. Nobody loses access
 *    they have paid for, and nobody is surprised.
 *
 * Both behaviours are configurable, because a future pricing model may want something else
 * (SL-BIL-006 §4).
 */
final class SubscriptionService
{
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
     * @return array{subscription: Subscription, effective: string, message: string}
     */
    public function changePlan(Tenant $tenant, Plan $target): array
    {
        return $this->tenancy->runAs($tenant, function () use ($tenant, $target): array {
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

            $isUpgrade = $target->unit_price_minor >= $subscription->plan->unit_price_minor;

            return DB::transaction(function () use ($subscription, $target, $isUpgrade, $tenant): array {
                if ($isUpgrade) {
                    $subscription->update([
                        'plan_id' => $target->getKey(),
                        'pending_plan_id' => null,
                        'pending_plan_starts_on' => null,
                    ]);

                    $this->entitlements->forget();

                    return [
                        'subscription' => $subscription->refresh(),
                        'effective' => 'immediately',
                        'message' => sprintf(
                            'You are on %s now. The new rate applies from %s — the rest of this period is at your old rate.',
                            $target->name,
                            $subscription->current_period_end->addDay()->toDateString(),
                        ),
                    ];
                }

                $losses = $this->whatWouldBeLost($tenant, $target);

                $subscription->update([
                    'pending_plan_id' => $target->getKey(),
                    'pending_plan_starts_on' => $subscription->current_period_end->addDay()->toDateString(),
                ]);

                return [
                    'subscription' => $subscription->refresh(),
                    'effective' => $subscription->current_period_end->addDay()->toDateString(),
                    // Told before it happens, not discovered afterwards.
                    'message' => $losses === []
                        ? sprintf('You will move to %s on %s. Nothing changes until then.',
                            $target->name, $subscription->current_period_end->addDay()->toDateString())
                        : sprintf('You will move to %s on %s. At that point: %s',
                            $target->name,
                            $subscription->current_period_end->addDay()->toDateString(),
                            implode(' ', $losses)),
                ];
            });
        });
    }

    /** Applies any plan change whose date has arrived. Runs daily. */
    public function applyPendingChanges(): int
    {
        $applied = 0;

        $tenants = $this->tenancy->withoutScoping(fn () => Tenant::query()->get());

        foreach ($tenants as $tenant) {
            $this->tenancy->runAs($tenant, function () use (&$applied): void {
                $due = Subscription::query()
                    ->whereNotNull('pending_plan_id')
                    ->whereDate('pending_plan_starts_on', '<=', now()->toDateString())
                    ->get();

                foreach ($due as $subscription) {
                    $subscription->update([
                        'plan_id' => $subscription->pending_plan_id,
                        'pending_plan_id' => null,
                        'pending_plan_starts_on' => null,
                        'current_period_start' => now()->startOfMonth()->toDateString(),
                        'current_period_end' => now()->endOfMonth()->toDateString(),
                    ]);

                    $applied++;
                }
            });
        }

        $this->entitlements->forget();

        return $applied;
    }

    public function cancel(Tenant $tenant, bool $immediately = false): Subscription
    {
        return $this->tenancy->runAs($tenant, function () use ($immediately): Subscription {
            $subscription = Subscription::query()->latest('id')->firstOrFail();

            $subscription->update($immediately
                ? ['status' => Subscription::CANCELLED, 'cancelled_at' => now()]
                // The default: service continues to the end of the period already paid for.
                : ['cancel_at_period_end' => true]);

            return $subscription->refresh();
        });
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
