<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The control plane's door.
 *
 * Separate from tenant authentication entirely, and closed to any operator who has not completed
 * two-factor setup — this account can reach every customer's data.
 */
final class EnsureOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $operator = $request->user('operator');

        if ($operator === null) {
            abort(401);
        }

        if (! $operator->is_active) {
            abort(403, 'This operator account is disabled.');
        }

        if (! $operator->hasTwoFactor()) {
            abort(403, 'Set up two-factor authentication before using the console.');
        }

        return $next($request);
    }
}
