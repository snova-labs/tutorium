<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * An API token, whose owner is found before any tenant is bound.
 *
 * Global: a token belongs to an operator or a user, and is looked up by its hash before anyone is
 * known. Its owner is then loaded across tenants for the same reason as TenantUserProvider: that
 * owner is what decides the request's tenant. Nothing else about tokens crosses tenants.
 */
// Not final: Sanctum::actingAs mocks this class in tests.
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** @return MorphTo<Model, $this> */
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')->withoutGlobalScope(TenantScope::class);
    }
}
