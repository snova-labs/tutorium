<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\TenantUserProvider;
use App\Models\PersonalAccessToken;
use App\Support\Payments\FakePaymentProvider;
use App\Support\Payments\ManualPaymentProvider;
use App\Support\Payments\PaymentProvider;
use App\Support\Payments\StripePaymentProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Which provider takes money is configuration (config/payments.php); no billing code asks.
        $this->app->singleton(PaymentProvider::class, fn (): PaymentProvider => match (config('payments.provider')) {
            'stripe' => new StripePaymentProvider(
                new StripeClient(['api_key' => config('payments.stripe.secret')]),
                (string) config('payments.stripe.webhook_secret'),
            ),
            'manual' => new ManualPaymentProvider,
            'fake' => new FakePaymentProvider,
            default => throw new InvalidArgumentException('Unknown payment provider: '.config('payments.provider')),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The demo page: its status is checked every few seconds while a build runs, so it gets a
        // limit of its own, apart from the password-guarded changes.
        RateLimiter::for('demo-status', fn (Request $request) => Limit::perMinute(60)->by('demo-status|'.$request->ip()));
        RateLimiter::for('demo-change', fn (Request $request) => Limit::perMinute(5)->by('demo-change|'.$request->ip()));

        // Who is signed in decides the tenant, so finding them cannot wait for a tenant to be bound.
        Auth::provider('tenant-eloquent', fn ($app, array $config): TenantUserProvider => new TenantUserProvider(
            $app['hash'],
            $config['model'],
        ));
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
