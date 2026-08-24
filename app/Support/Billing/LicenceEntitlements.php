<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Tenant;

/**
 * Self-hosted mode: answers come from a signed licence file, validated offline.
 *
 * Present now, unused until P5. It exists at this point so that every entitlement check written
 * from here on goes through the interface rather than reaching for a subscription — which is the
 * only thing that keeps the self-hosted product from becoming a fork (SL-ARC-002 §10).
 */
final class LicenceEntitlements implements EntitlementSource
{
    /** @param array<string, mixed> $licence */
    public function __construct(private readonly array $licence = []) {}

    public function resolve(Tenant $tenant, string $key): ?Entitlement
    {
        $features = $this->licence['features'] ?? [];

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
        );
    }

    /** @return array<string, Entitlement> */
    public function all(Tenant $tenant): array
    {
        $entitlements = [];

        foreach (($this->licence['features'] ?? []) as $key => $value) {
            $entitlements[$key] = $this->resolve($tenant, $key);
        }

        return array_filter($entitlements);
    }

    public function name(): string
    {
        return 'licence';
    }
}
