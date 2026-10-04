<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters every query on a tenant-owned model by the bound tenant.
 *
 * This is the primary isolation control (SL-SEC-004 §3). It is deliberately not optional per
 * query: code that needs to cross tenants must say so loudly via TenantContext::withoutScoping().
 */
/** @implements Scope<Model> */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isSuspended()) {
            return;
        }

        // With no tenant bound, a tenant-owned query must return nothing rather than everything.
        // Failing open here is how multi-tenant products leak.
        // Only ever attached by BelongsToTenant, which provides the column name.
        $column = method_exists($model, 'getTenantColumn') ? $model->getTenantColumn() : 'tenant_id';

        $builder->where(
            $model->qualifyColumn($column),
            $context->id() ?? 0,
        );
    }
}
