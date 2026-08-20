<?php

declare(strict_types=1);

use App\Support\Tenancy\RequiresTenant;
use App\Support\Tenancy\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tenant resolution runs on every authenticated route, for both the web
        // interface and the API. The tenant is derived from the credential, never
        // from a route parameter — that would let a caller choose their own tenant.
        $middleware->web(append: [ResolveTenant::class]);
        $middleware->api(append: [ResolveTenant::class]);

        $middleware->alias([
            'tenant' => RequiresTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never render a tenancy failure to a user as a validation message — it is a
        // programming error and must be loud in logs, generic on screen.
        $exceptions->render(function (\App\Support\Tenancy\TenancyException $e) {
            report($e);

            return response()->json(['message' => 'Request could not be completed.'], 500);
        });
    })
    ->create();
