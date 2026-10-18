<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Tenant;
use App\Support\Licensing\Licence;

/**
 * Self-hosted mode: answers come from a signed licence file, validated offline.
 *
 * Nothing in the product knows it is being answered by a licence rather than by a subscription,
 * which is the only thing that keeps the self-hosted product from becoming a fork (SL-ARC-002 §10).
 * An install with no readable licence answers nothing at all, and the shipped defaults apply —
 * refusing every question would take the register away from a school over a renewal.
 */
final class LicenceEntitlements implements EntitlementSource
{
    public function __construct(private readonly ?Licence $licence = null) {}

    public function resolve(Tenant $tenant, string $key): ?Entitlement
    {
        $features = $this->licence?->entitlements() ?? [];

        if (! array_key_exists($key, $features)) {
            return null;
        }

        return new Entitlement(
            key: $key,
            value: $features[$key],
            source: Entitlement::SOURCE_LICENCE,
            // A licence cap warns and blocks new enrollments; it never stops a teacher recording
            // attendance for learners who are already there (FR-LIC-1).
            isHard: $key === 'max_learners',
            // A cap is the one answer somebody will argue about, so it says which licence it
            // came from. A key present in the features means there is a licence to name.
            reason: $key === 'max_learners'
                ? sprintf('Licence %s, %s', $this->licence->id, $this->licence->licensee)
                : null,
        );
    }

    /** @return array<string, Entitlement> */
    public function all(Tenant $tenant): array
    {
        $entitlements = [];

        foreach (($this->licence?->entitlements() ?? []) as $key => $value) {
            $entitlements[$key] = $this->resolve($tenant, $key);
        }

        return array_filter($entitlements);
    }

    public function name(): string
    {
        return 'licence';
    }
}
