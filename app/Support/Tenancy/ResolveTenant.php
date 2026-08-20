<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant for the request from the authenticated user.
 *
 * The tenant is never taken from a route parameter, header or query string — that would let a
 * caller choose their own tenant. When subdomain and custom-domain routing arrive (P4), the
 * resolved domain is cross-checked against the user's tenant here rather than replacing it.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->tenant !== null) {
            $this->context->set($user->tenant);

            if ($user->tenant->isPurged()) {
                abort(410, 'This account has been closed.');
            }
        }

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
