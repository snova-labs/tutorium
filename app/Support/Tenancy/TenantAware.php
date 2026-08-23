<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;

/**
 * Carries the tenant across the queue boundary.
 *
 * Background work is where multi-tenant products actually leak: the request context is gone, so
 * an unscoped query silently reads every tenant's rows. A job using this trait captures the
 * tenant id at dispatch and re-binds it before handling. A job that cannot resolve one fails
 * loudly rather than running unscoped.
 */
trait TenantAware
{
    public ?int $tenantId = null;

    public function initializeTenantAware(): void
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new BindsTenantContext];
    }

    public function resolveTenant(): Tenant
    {
        if ($this->tenantId === null) {
            throw TenancyException::unresolvableJob(static::class);
        }

        $tenant = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::query()->find($this->tenantId),
        );

        return $tenant ?? throw TenancyException::unresolvableJob(static::class);
    }
}
