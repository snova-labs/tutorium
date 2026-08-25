<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolves the login identity before a tenant is bound.
 *
 * Authentication is the one place where the tenant cannot already be known: the tenant is derived
 * from the user, so the user must be found first. Every other query on this model stays scoped —
 * only these three lookups are exempt, and each returns exactly one row identified by a unique
 * credential, never a list.
 *
 * @see TenantScope
 */
final class TenantAwareUserProvider extends EloquentUserProvider
{
    /** @param array<string, mixed> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return app(TenantContext::class)->withoutScoping(
            fn () => parent::retrieveByCredentials($credentials),
        );
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        return app(TenantContext::class)->withoutScoping(
            fn () => parent::retrieveById($identifier),
        );
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return app(TenantContext::class)->withoutScoping(
            fn () => parent::retrieveByToken($identifier, $token),
        );
    }
}