<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ClassSession;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\AttendancePolicyGate;
use App\Policies\ClassSessionPolicy;
use App\Support\Audit\AuditContext;
use App\Support\Billing\EntitlementSource;
use App\Support\Billing\LicenceEntitlements;
use App\Support\Billing\SubscriptionEntitlements;
use App\Support\Grading\GradingRegistry;
use App\Support\Mail\MailProvider;
use App\Support\Mail\N8nMailProvider;
use App\Support\Mail\SmtpMailProvider;
use App\Support\Payments\FakePaymentProvider;
use App\Support\Payments\ManualPaymentProvider;
use App\Support\Payments\PaymentProvider;
use App\Support\Payments\StripePaymentProvider;
use App\Support\Pdf\ChromePdfRenderer;
use App\Support\Pdf\PdfRenderer;
use App\Support\Pdf\PhpPdfRenderer;
use App\Support\Presets\PresetRepository;
use App\Support\Sequences\IdSequenceService;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Terminology\Terminology;
use App\Services\EntitlementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;
use App\Support\Tenancy\TenantAwareUserProvider;
use Illuminate\Support\Facades\Auth;


/**
 * Every binding the application needs, in one place — canonical.
 *
 * This replaces the version each layer asked you to edit. Grouped by concern, with the driver
 * choices at the bottom so switching a PDF renderer or a payment provider is one obvious edit.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Request-scoped state ─────────────────────────────────────────────
        // Scoped, not singleton: each resolves once per request or job, and must not survive a
        // tenant change inside a queue worker.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(AuditContext::class);
        $this->app->scoped(SettingsResolver::class);
        $this->app->scoped(IdSequenceService::class);
        $this->app->scoped(Terminology::class);
        $this->app->scoped(EntitlementService::class);

        // ── Stateless registries ─────────────────────────────────────────────
        $this->app->singleton(GradingRegistry::class);
        $this->app->singleton(PresetRepository::class);

        // ── Drivers ──────────────────────────────────────────────────────────
        $this->bindPdfRenderer();
        $this->bindMailProvider();
        $this->bindEntitlementSource();
        $this->bindPaymentProvider();
    }

    public function boot(): void
    {
        // Fail loudly on N+1 and on assigning attributes that do not exist. Both are bugs that are
        // cheap to catch here and expensive to find in production.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        

        $this->keepPermissionsInStepWithTenant();
        $this->grantOwnerEverything();
        $this->registerGates();

        Auth::provider('tenant-aware', fn ($app, array $config) => new TenantAwareUserProvider(
            $app['hash'],
            $config['model'],
        ));
    }

    /**
     * Roles are per tenant, so the permission package keeps its own notion of which one.
     *
     * Bound to the one place the tenant actually changes, rather than remembered at each entry
     * point — middleware, jobs, console commands and tests all get it for free.
     */
    private function keepPermissionsInStepWithTenant(): void
    {
        $this->app->make(TenantContext::class)->onChange(function (?Tenant $tenant): void {
            $registrar = $this->app->make(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId($tenant?->getKey());
            // The cached map belongs to the previous tenant; keeping it would let one account's
            // roles answer another account's questions.
            $registrar->forgetCachedPermissions();
        });
    }

    /**
     * The owner passes every check through a Gate rule rather than a stored permission list, so a
     * permission added in a later release cannot lock an owner out of their own account.
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

    /**
     * Recording a register and rescheduling a class are different jobs held by different people,
     * so attendance has its own abilities alongside the session policy.
     */
    private function registerGates(): void
    {
        Gate::policy(ClassSession::class, ClassSessionPolicy::class);

        Gate::define('viewRoster', [AttendancePolicyGate::class, 'viewRoster']);
        Gate::define('record', [AttendancePolicyGate::class, 'record']);
    }

    private function bindPdfRenderer(): void
    {
        $this->app->bind(PdfRenderer::class, fn () => config('reporting.pdf.renderer') === 'chrome'
            ? new ChromePdfRenderer((string) config('reporting.pdf.chrome_binary'))
            : new PhpPdfRenderer);
    }

    private function bindMailProvider(): void
    {
        $this->app->bind(MailProvider::class, fn ($app) => config('reporting.mail.provider') === 'n8n'
            ? new N8nMailProvider(
                (string) config('reporting.mail.n8n_webhook'),
                config('reporting.mail.n8n_secret'),
            )
            : new SmtpMailProvider($app['mailer']));
    }

    private function bindEntitlementSource(): void
    {
        $this->app->bind(EntitlementSource::class, function ($app) {
            if (config('platform.deployment_mode') !== 'self_hosted') {
                return new SubscriptionEntitlements($app->make(TenantContext::class));
            }

            $path = storage_path('app/licence.json');
            $licence = is_file($path)
                ? (json_decode((string) file_get_contents($path), true) ?: [])
                : [];

            return new LicenceEntitlements($licence);
        });
    }

    private function bindPaymentProvider(): void
    {
        $this->app->singleton(PaymentProvider::class, fn () => match (config('payments.provider')) {
            'stripe' => new StripePaymentProvider(
                new \Stripe\StripeClient((string) config('payments.stripe.secret')),
                (string) config('payments.stripe.webhook_secret'),
            ),
            // Exercises the whole billing path offline. Never in production.
            'fake' => new FakePaymentProvider,
            default => new ManualPaymentProvider,
        });
    }
}