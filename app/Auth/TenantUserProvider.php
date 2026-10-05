<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\Tenancy\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds the signed-in user before any tenant is bound.
 *
 * Authentication is what decides the tenant: a request's tenant is the signed-in user's. So the
 * lookup of that one user (by id from the session, or by credentials) cannot itself be filtered by
 * a tenant, or the tenant scope, failing closed, hides everyone and nobody stays signed in.
 *
 * Only this lookup crosses tenants. Everything after it runs with the user's tenant bound.
 */
final class TenantUserProvider extends EloquentUserProvider
{
    /**
     * @template TModel of Model
     *
     * @param TModel|null $model
     * @return Builder<TModel>
     */
    protected function newModelQuery($model = null): Builder
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}
