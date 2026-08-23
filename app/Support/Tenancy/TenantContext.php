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
 * @see TenantScope
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    /** True while running control-plane work that must see across tenants. */
    private bool $suspended = false;

    /**
     * Callbacks fired whenever the bound tenant changes.
     *
     * This exists so that packages keeping their own tenant state — the permission registrar,
     * for one — stay in step without this class having to know they exist.
     *
     * @var array<int, Closure(?Tenant):void>
     */
    private array $listeners = [];

    public function onChange(Closure $listener): void
    {
        $this->listeners[] = $listener;
        $listener($this->tenant);
    }

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->notify();
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

    /** The tenant, or a hard failure. Use where operating without one is a bug, not a state. */
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
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public function runAs(Tenant $tenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousSuspended = $this->suspended;

        $this->tenant = $tenant;
        $this->suspended = false;
        $this->notify();

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->suspended = $previousSuspended;
            $this->notify();
        }
    }

    /**
     * Run a callback with scoping disabled. Reserved for control-plane work (metering, operator
     * console, provisioning). Never acceptable inside tenant-facing request handling.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
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
        $this->notify();
    }

    private function notify(): void
    {
        foreach ($this->listeners as $listener) {
            $listener($this->tenant);
        }
    }
}
