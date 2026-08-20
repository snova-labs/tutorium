<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Sequences\IdSequenceService;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One context per request/job lifecycle.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(SettingsResolver::class);
        $this->app->scoped(IdSequenceService::class);
    }

    public function boot(): void
    {
        // Fail on N+1 and on assigning attributes that do not exist. Both are bugs that are
        // cheap to catch here and expensive to find in production.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::unguard(false);
    }
}
