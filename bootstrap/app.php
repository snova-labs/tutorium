<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Operator\AuthController as OperatorAuthController;
use App\Http\Controllers\Webhook\PaymentWebhookController;
use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\PreserveFloatTypes;
use App\Http\Middleware\RestrictImpersonatedAccess;
use App\Support\Tenancy\RequiresTenant;
use App\Support\Tenancy\ResolveTenant;
use App\Support\Tenancy\TenancyException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // Probes sit outside the web group so they never start a session or bind a tenant.
            Route::get('up', [HealthController::class, 'live'])->name('health.live');
            Route::get('ready', [HealthController::class, 'ready'])->name('health.ready');

            // The payment provider's callback. Outside both the web group (no session, no CSRF,
            // no tenant resolution) and the API group: the caller is the provider, not a user, and
            // the controller verifies the signature before reading anything.
            Route::post('webhooks/payments', [PaymentWebhookController::class, 'handle'])
                ->name('webhooks.payments');

            // Operator sign-in and two-factor enrolment come before the console guard: the first
            // has no token yet, the second holds only an enrolment token.
            Route::middleware('api')->prefix('operator/v1/auth')->group(function (): void {
                Route::post('login', [OperatorAuthController::class, 'login'])
                    ->middleware('throttle:10,1')->name('operator.auth.login');

                Route::middleware(['auth:operator', 'abilities:'.OperatorAuthController::ABILITY_ENROL])
                    ->group(function (): void {
                        Route::post('two-factor/enrol', [OperatorAuthController::class, 'enrol'])
                            ->name('operator.auth.two-factor.enrol');
                        Route::post('two-factor/confirm', [OperatorAuthController::class, 'confirm'])
                            ->middleware('throttle:10,1')->name('operator.auth.two-factor.confirm');
                    });
            });

            Route::middleware(['api', 'auth:operator', 'operator'])
                ->prefix('operator/v1')
                ->group(base_path('routes/operator.php'));
        },
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
            'operator' => EnsureOperator::class,
            'abilities' => CheckAbilities::class,
        ]);
        $middleware->api(append: [RestrictImpersonatedAccess::class, PreserveFloatTypes::class]);

        // The tenant must be bound before route model binding runs: a {session} or {batch} looked
        // up with no tenant bound fails closed and the request becomes a 404. Placing it just
        // before SubstituteBindings keeps it after authentication, which it needs for the user.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);

        // The sign-in route is called sign-in; the framework's default redirect looks for "login"
        // and turned every guest visit to a signed-in page into a 500.
        $middleware->redirectGuestsTo(fn () => route('sign-in'));
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
