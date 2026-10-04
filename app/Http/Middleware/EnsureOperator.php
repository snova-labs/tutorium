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
 * Separate from tenant authentication entirely, and closed to any token that was not issued after
 * a second factor at sign-in — this account can reach every customer's data.
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

        // Console tokens are only issued once a second factor (emailed code, authenticator app or
        // recovery code) has been proved; nothing else opens the console.
        if (! $operator->tokenCan(AuthController::ABILITY_CONSOLE)) {
            abort(403, 'Sign in with two-factor authentication before using the console.');
        }

        return $next($request);
    }
}
