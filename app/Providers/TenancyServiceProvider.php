<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ClassSession;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\AttendancePolicyGate;
use App\Policies\ClassSessionPolicy;
use App\Support\Audit\AuditContext;
use App\Support\Grading\GradingRegistry;
use App\Support\Sequences\IdSequenceService;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(AuditContext::class);
        $this->app->scoped(SettingsResolver::class);
        $this->app->scoped(IdSequenceService::class);

        // Every freshly-built context gets the listener — including the ones the queue
        // worker creates after forgetScopedInstances(). Registering in boot() would
        // attach it to exactly one instance and silently miss the rest.
        $this->app->resolving(
            TenantContext::class,
            fn (TenantContext $context) => $context->onChange($this->syncPermissionTenant()),
        );

        $this->app->singleton(GradingRegistry::class);
    }

    public function boot(): void
    {
        // Fail on N+1 and on assigning attributes that do not exist. Both are bugs that are
        // cheap to catch here and expensive to find in production.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // $this->keepPermissionsInStepWithTenant();
        $this->grantOwnerEverything();

        Gate::policy(ClassSession::class, ClassSessionPolicy::class);

        Gate::define('viewRoster', [AttendancePolicyGate::class, 'viewRoster']);
        Gate::define('record', [AttendancePolicyGate::class, 'record']);
    }

    private function syncPermissionTenant(): Closure
    {
        return function (?Tenant $tenant): void {
            $registrar = $this->app->make(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId($tenant?->getKey());
            $registrar->clearClassPermissions();
        };
    }

    /**
     * Roles and permissions are per tenant, so the permission package keeps its own notion of the
     * current tenant. Rather than remembering to set it at every entry point — middleware, jobs,
     * console commands, tests — it is bound to the one place the tenant actually changes.
     */
    private function keepPermissionsInStepWithTenant(): void
    {
        $this->app->make(TenantContext::class)->onChange(
            function (?Tenant $tenant): void {
                $registrar = $this->app->make(PermissionRegistrar::class);
                $registrar->setPermissionsTeamId($tenant?->getKey());
                // The cached permission map belongs to the previous tenant; keeping it would let
                // one account's roles answer another account's questions.
                $registrar->forgetCachedPermissions();
            },
        );
    }

    /**
     * The owner passes every check through a Gate rule rather than a stored permission list.
     *
     * Storing the list would mean that adding a permission in a later release leaves existing
     * owners locked out of their own account until a migration catches up.
     */
    private function grantOwnerEverything(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->is_active) {
                return false;
            }

            return $user->isOwner() ? true : null;
        });
    }
}
