<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Payments\FakePaymentProvider;
use App\Support\Payments\ManualPaymentProvider;
use App\Support\Payments\PaymentProvider;
use App\Support\Payments\StripePaymentProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
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
        //
    }
}
