<?php

declare(strict_types=1);

use App\Support\Tenancy\RequiresTenant;
use App\Support\Tenancy\ResolveTenant;
use App\Support\Tenancy\TenancyException;
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
        // On the web the session has already resolved the user by the time this runs, so the
        // tenant can be bound for the whole group.
        $middleware->web(append: [ResolveTenant::class]);

        // On the API the user only exists after auth:sanctum, so tenant resolution is applied
        // per route group *after* authentication rather than globally. Binding it globally would
        // silently leave every API request with no tenant — and a query that finds nothing.
        $middleware->alias([
            'tenant.resolve' => ResolveTenant::class,
            'tenant' => RequiresTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A tenancy failure is a programming error, not something to explain to a caller: loud
        // in the logs, generic on screen.
        $exceptions->render(function (TenancyException $e) {
            report($e);

            return response()->json(['message' => 'Request could not be completed.'], 500);
        });
    })
    ->create();
