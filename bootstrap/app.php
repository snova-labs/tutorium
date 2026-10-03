<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\RestrictImpersonatedAccess;
use App\Support\Tenancy\RequiresTenant;
use App\Support\Tenancy\ResolveTenant;
use App\Support\Tenancy\TenancyException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // The control plane, behind its own guard and deliberately not under /api/v1 — it is
            // not part of the product's API, and an operator is not a tenant user with extra
            // permissions.
            Route::middleware(['api', 'auth:operator', 'operator'])
                ->prefix('operator/v1')
                ->group(base_path('routes/operator.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // On the web, the session has already resolved the user by the time this runs.
        $middleware->web(append: [ResolveTenant::class]);

        // On the API the user only exists after auth:sanctum, so tenant resolution is applied
        // per route group *after* authentication rather than globally.
        $middleware->alias([
            'tenant.resolve' => ResolveTenant::class,
            'tenant' => RequiresTenant::class,
            'operator' => EnsureOperator::class,
        ]);

        // Attributes support writes to the operator by name, and blocks billing changes and
        // deletions while a support session is active.
        $middleware->api(append: [RestrictImpersonatedAccess::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A tenancy failure is a programming error, not something to explain to a caller: loud in
        // the logs, generic on screen.
        $exceptions->render(function (TenancyException $e) {
            report($e);

            return response()->json(['message' => 'Request could not be completed.'], 500);
        });
    })
    ->create();