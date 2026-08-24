<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Tenant;

/**
 * Where an entitlement answer comes from.
 *
 * Two implementations: a cloud subscription, and a signed licence file for a self-hosted install.
 * Nothing in the product knows which is in play — that is the whole reason the interface exists,
 * and the reason the self-hosted product is packaging work rather than a fork (FR-ENT-2).
 */
interface EntitlementSource
{
    public function resolve(Tenant $tenant, string $key): ?Entitlement;

    /** @return array<string, Entitlement> */
    public function all(Tenant $tenant): array;

    public function name(): string;
}
