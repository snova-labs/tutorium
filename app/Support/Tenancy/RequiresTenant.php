<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards routes that are meaningless without a tenant, and enforces what each tenant state
 * permits (SL-BIL-006 §6).
 *
 * The rule this encodes: non-payment restricts service, never access to one's own data. A
 * suspended tenant keeps reading and exporting; it stops writing.
 */
final class RequiresTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->get();

        if ($tenant === null) {
            abort(403, 'No tenant context.');
        }

        if ($tenant->isSuspended() && ! $request->isMethodSafe()) {
            abort(423, 'This account is read-only. Your data remains available for export.');
        }

        return $next($request);
    }
}
