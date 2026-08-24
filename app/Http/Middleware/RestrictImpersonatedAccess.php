<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Impersonation;
use App\Support\Audit\AuditContext;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What support access may and may not do.
 *
 * Two jobs. It blocks the things nobody should ever do on a customer's behalf — changing what
 * they pay, deleting their records — and it attributes every write to the operator rather than to
 * the user whose seat they are borrowing. Support appearing in a customer's log as the customer
 * would make the log a lie.
 */
final class RestrictImpersonatedAccess
{
    /** Paths support access may never reach, regardless of what the borrowed account can do. */
    private const FORBIDDEN = [
        'api/v1/billing*',
        'api/v1/subscription*',
        'api/v1/tenants/*/purge',
    ];

    public function __construct(
        private readonly AuditContext $audit,
        private readonly TenantContext $tenancy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null || ! $token->can('impersonate')) {
            return $next($request);
        }

        foreach (self::FORBIDDEN as $pattern) {
            if ($request->is($pattern)) {
                abort(403, 'Support access cannot change billing or delete an account.');
            }
        }

        if ($request->isMethod('DELETE')) {
            abort(403, 'Support access cannot delete records. Ask the account owner to do it.');
        }

        $impersonation = Impersonation::query()
            ->whereNull('ended_at')
            ->where('user_id', $request->user()->getKey())
            ->where('expires_at', '>', now())
            ->with('operator')
            ->latest('id')
            ->first();

        if ($impersonation === null) {
            abort(403, 'This support session has ended.');
        }

        // Every write from here on is attributed to the operator, by name, in the tenant's log.
        $this->audit->actingAsOperator(
            $impersonation->operator_id,
            $impersonation->operator->name.' (support)',
        );

        $response = $next($request);

        // Made obvious to anything rendering the response, so a support session can never be
        // mistaken for ordinary use.
        $response->headers->set('X-Support-Access', 'active');
        $response->headers->set('X-Support-Expires', $impersonation->expires_at->toIso8601String());

        return $response;
    }
}
