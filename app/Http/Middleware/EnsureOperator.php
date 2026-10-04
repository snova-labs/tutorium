<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Operator\AuthController;
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

        // A token issued before the second factor (enrolment only) never opens the console.
        if (! $operator->hasTwoFactor() || ! $operator->tokenCan(AuthController::ABILITY_CONSOLE)) {
            abort(403, 'Sign in with two-factor authentication before using the console.');
        }

        return $next($request);
    }
}
