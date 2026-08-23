<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned.
 *
 * Applying this trait does three things: scopes every query, stamps the tenant on create, and
 * refuses writes that would cross a tenant boundary. Every model using it must also be listed in
 * config('tenancy.resources') — TenantRegistryTest fails the build otherwise.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            $context = app(TenantContext::class);
            $column = $model->getTenantColumn();

            if ($model->getAttribute($column) === null) {
                // Control-plane and system writes may legitimately have no tenant — a
                // platform-level audit entry, for instance. Those must call withoutScoping()
                // explicitly, which is what makes the exception visible in review.
                if ($context->isSuspended()) {
                    return;
                }

                $model->setAttribute($column, $context->require()->getKey());

                return;
            }

            // An explicitly set tenant is allowed only when it matches the bound context, or
            // when scoping is deliberately suspended (provisioning, control plane, imports).
            if (! $context->isSuspended() && $model->getAttribute($column) !== $context->id()) {
                throw TenancyException::mismatch(
                    static::class,
                    (string) $context->id(),
                    (string) $model->getAttribute($column),
                );
            }
        });

        static::updating(function (self $model): void {
            $column = $model->getTenantColumn();

            if ($model->isDirty($column)) {
                throw TenancyException::mismatch(
                    static::class,
                    (string) $model->getOriginal($column),
                    (string) $model->getAttribute($column),
                );
            }
        });
    }

    public function getTenantColumn(): string
    {
        return 'tenant_id';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
