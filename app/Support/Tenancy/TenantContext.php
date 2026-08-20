<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * Holds the tenant bound to the current request, job or command.
 *
 * Nothing in the application may read a tenant identifier from user input. The context is set
 * once, at the edge (ResolveTenant middleware for requests, TenantAware for jobs), and every
 * query is scoped from it automatically.
 *
 * @see \App\Support\Tenancy\TenantScope
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    /** True while running control-plane work that must see across tenants. */
    private bool $suspended = false;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * The tenant, or a hard failure. Use where operating without one is a bug rather than a state.
     */
    public function require(): Tenant
    {
        return $this->tenant ?? throw TenancyException::missingContext();
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    /**
     * Run a callback bound to a specific tenant, restoring the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $previousSuspended = $this->suspended;

        $this->tenant = $tenant;
        $this->suspended = false;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
            $this->suspended = $previousSuspended;
        }
    }

    /**
     * Run a callback with scoping disabled. Reserved for control-plane work (metering, operator
     * console, provisioning). Never acceptable inside tenant-facing request handling.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutScoping(Closure $callback): mixed
    {
        $previous = $this->suspended;
        $this->suspended = true;

        try {
            return $callback();
        } finally {
            $this->suspended = $previous;
        }
    }

    public function forget(): void
    {
        $this->tenant = null;
        $this->suspended = false;
    }
}
