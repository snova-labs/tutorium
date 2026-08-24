<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Support\Tenancy\TenantContext;

/** Cloud mode: answers come from the plan, with negotiated overrides on top. */
final class SubscriptionEntitlements implements EntitlementSource
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function resolve(Tenant $tenant, string $key): ?Entitlement
    {
        $override = $this->override($tenant, $key);

        if ($override !== null) {
            return $override;
        }

        $subscription = $this->subscriptionFor($tenant);

        if ($subscription === null) {
            return null;
        }

        $feature = $subscription->plan->features->firstWhere('feature_key', $key);

        if ($feature === null) {
            return null;
        }

        return new Entitlement(
            key: $key,
            value: $feature->value['value'] ?? $feature->value,
            source: Entitlement::SOURCE_PLAN,
            isHard: $feature->isHard(),
        );
    }

    /** @return array<string, Entitlement> */
    public function all(Tenant $tenant): array
    {
        $entitlements = [];
        $subscription = $this->subscriptionFor($tenant);

        foreach ($subscription?->plan->features ?? [] as $feature) {
            $entitlements[$feature->feature_key] = new Entitlement(
                key: $feature->feature_key,
                value: $feature->value['value'] ?? $feature->value,
                source: Entitlement::SOURCE_PLAN,
                isHard: $feature->isHard(),
            );
        }

        foreach ($this->overrides($tenant) as $override) {
            $entitlements[$override->feature_key] = new Entitlement(
                key: $override->feature_key,
                value: $override->value['value'] ?? $override->value,
                source: Entitlement::SOURCE_OVERRIDE,
                isHard: false,
                reason: $override->reason,
            );
        }

        return $entitlements;
    }

    public function name(): string
    {
        return 'subscription';
    }

    private function override(Tenant $tenant, string $key): ?Entitlement
    {
        $override = $this->overrides($tenant)->firstWhere('feature_key', $key);

        if ($override === null) {
            return null;
        }

        return new Entitlement(
            key: $key,
            value: $override->value['value'] ?? $override->value,
            source: Entitlement::SOURCE_OVERRIDE,
            // A negotiated grant never blocks: it exists because someone decided this account
            // should be allowed more, not less.
            isHard: false,
            reason: $override->reason,
        );
    }

    /** @return \Illuminate\Support\Collection<int, TenantEntitlementOverride> */
    private function overrides(Tenant $tenant): \Illuminate\Support\Collection
    {
        return $this->tenancy->runAs(
            $tenant,
            fn () => TenantEntitlementOverride::query()->get()->filter->isCurrent()->values(),
        );
    }

    private function subscriptionFor(Tenant $tenant): ?Subscription
    {
        return $this->tenancy->runAs(
            $tenant,
            fn () => Subscription::query()
                ->with('plan.features')
                ->whereIn('status', [Subscription::TRIALING, Subscription::ACTIVE, Subscription::PAST_DUE])
                ->latest('id')
                ->first(),
        );
    }
}
