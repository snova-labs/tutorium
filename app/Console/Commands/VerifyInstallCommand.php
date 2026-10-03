<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Checks that the application is wired together correctly.
 *
 * This exists because the system was assembled in layers, each of which asked for a few manual
 * edits to shared files — the tenancy registry, the route file, the service provider. Every one of
 * those is a place where a missed line produces a failure that surfaces somewhere unrelated and
 * days later. Rather than trusting a checklist, this asks the running application directly.
 *
 * Run it after any install step, and in CI.
 */
final class VerifyInstallCommand extends Command
{
    protected $signature = 'platform:verify {--strict : Treat warnings as failures}';

    protected $description = 'Check that models, bindings, routes, tables and schedules are all wired up';

    /** @var array<int, array{level: string, area: string, message: string}> */
    private array $findings = [];

    public function handle(): int
    {
        $this->components->info('Verifying the installation');

        $this->checkTenantRegistry();
        $this->checkTables();
        $this->checkBindings();
        $this->checkRoutes();
        $this->checkRelations();
        $this->checkSeederOrder();

        return $this->report();
    }

    /**
     * Every model using the tenancy trait must be declared, and everything declared must use it.
     *
     * A model missing from the registry has no isolation coverage, which is the one failure this
     * architecture exists to prevent.
     */
    private function checkTenantRegistry(): void
    {
        $registered = collect(config('tenancy.resources', []));
        $global = collect(config('tenancy.global_models', []));
        $found = collect();

        foreach (File::allFiles(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($class)) {
                continue;
            }

            $uses = array_keys((new ReflectionClass($class))->getTraits());

            if (in_array(BelongsToTenant::class, $uses, true)) {
                $found->push($class);
            } elseif (! $global->contains($class)) {
                $this->warn_(
                    'tenancy',
                    class_basename($class).' is neither tenant-owned nor declared global. '
                    .'Add it to config/tenancy.php so the decision is explicit.',
                );
            }
        }

        foreach ($found->diff($registered) as $missing) {
            $this->fail_(
                'tenancy',
                class_basename($missing).' uses BelongsToTenant but is missing from '
                .'config(\'tenancy.resources\') — it has no isolation coverage.',
            );
        }

        foreach ($registered->diff($found) as $stale) {
            $this->fail_(
                'tenancy',
                class_basename($stale).' is registered as tenant-owned but does not use BelongsToTenant.',
            );
        }

        $this->components->twoColumnDetail(
            'Tenant registry',
            $found->count().' tenant-owned, '.$global->count().' global',
        );
    }

    private function checkTables(): void
    {
        $required = [
            'tenants', 'brands', 'branches', 'users', 'settings', 'id_sequences', 'audit_logs',
            'courses', 'batches', 'timetable_slots', 'class_sessions', 'reporting_periods', 'holidays',
            'learners', 'guardians', 'guardian_learner', 'enrollments', 'enrollment_status_history',
            'attendance_statuses', 'attendance_policies', 'attendance_records', 'makeup_links',
            'assessment_types', 'grading_schemes', 'assessments', 'rubric_criteria', 'grades',
            'grade_rubric_scores', 'type_weights', 'submission_statuses',
            'note_categories', 'teacher_notes', 'report_templates', 'reports', 'report_runs',
            'report_deliveries', 'email_templates',
            'terminology_overrides', 'preset_applications', 'sample_data_sets',
            'operators', 'impersonations', 'tenant_exports',
            'plans', 'plan_features', 'subscriptions', 'usage_snapshots', 'invoices',
            'tenant_entitlement_overrides', 'billing_profiles', 'dunning_attempts', 'webhook_events',
            'roles', 'permissions', 'model_has_roles',
        ];

        $missing = array_values(array_filter($required, fn (string $t) => ! Schema::hasTable($t)));

        foreach ($missing as $table) {
            $this->fail_('database', "Table [{$table}] does not exist. A migration has not run.");
        }

        // The teams column is what makes roles per tenant. Without it one academy renaming a role
        // would change what that role means for everyone.
        if (Schema::hasTable('roles') && ! Schema::hasColumn('roles', 'tenant_id')) {
            $this->fail_(
                'database',
                'The roles table has no tenant_id column. Set teams => true and '
                ."team_foreign_key => 'tenant_id' in config/permission.php, then re-run the migration.",
            );
        }

        $this->components->twoColumnDetail(
            'Tables',
            (count($required) - count($missing)).' of '.count($required).' present',
        );
    }

    private function checkBindings(): void
    {
        $bindings = [
            \App\Support\Tenancy\TenantContext::class => 'tenancy',
            \App\Support\Audit\AuditContext::class => 'audit',
            \App\Support\Settings\SettingsResolver::class => 'settings',
            \App\Support\Sequences\IdSequenceService::class => 'sequences',
            \App\Support\Grading\GradingRegistry::class => 'grading',
            \App\Support\Terminology\Terminology::class => 'terminology',
            \App\Support\Presets\PresetRepository::class => 'presets',
            \App\Support\Pdf\PdfRenderer::class => 'reporting',
            \App\Support\Mail\MailProvider::class => 'reporting',
            \App\Support\Billing\EntitlementSource::class => 'billing',
            \App\Support\Payments\PaymentProvider::class => 'billing',
        ];

        $resolved = 0;

        foreach ($bindings as $abstract => $area) {
            try {
                app()->make($abstract);
                $resolved++;
            } catch (BindingResolutionException $e) {
                $this->fail_(
                    $area,
                    class_basename($abstract).' cannot be resolved. Add its binding to '
                    .'TenancyServiceProvider::register().',
                );
            } catch (Throwable $e) {
                $this->fail_($area, class_basename($abstract).' failed to build: '.$e->getMessage());
            }
        }

        $this->components->twoColumnDetail('Container bindings', $resolved.' of '.count($bindings).' resolve');
    }

    private function checkRoutes(): void
    {
        $required = [
            'api.auth.login', 'api.me',
            'api.brands.index', 'api.branches.index',
            'api.courses.index', 'api.batches.index', 'api.batches.sessions.generate', 'api.batches.periods',
            'api.learners.index', 'api.enrollments.index', 'api.enrollments.transfer',
            'api.sessions.attendance.roster', 'api.sessions.attendance.save', 'api.batches.attendance.summary',
            'api.batches.gradebook', 'api.batches.gradebook.save', 'api.enrollments.average',
            'api.notes.index', 'api.notes.bulk',
            'api.reports.readiness', 'api.reports.generate', 'api.reports.send', 'api.reports.preview',
            'api.onboarding.status', 'api.presets.index', 'api.terminology.index',
            'api.usage.meter', 'api.billing.show',
            'webhooks.payments',
            'operator.tenants.index', 'operator.impersonation.start',
        ];

        $names = collect(Route::getRoutes())->map->getName()->filter()->flip();
        $missing = array_values(array_filter($required, fn (string $n) => ! $names->has($n)));

        foreach ($missing as $name) {
            $this->fail_('routes', "Route [{$name}] is not registered. A route block was not pasted in.");
        }

        // The order matters and the failure is silent: bound globally, tenant resolution would run
        // before authentication and leave every API request with no tenant and no data.
        $aliases = app('router')->getMiddleware();

        foreach (['tenant.resolve', 'tenant', 'operator'] as $alias) {
            if (! array_key_exists($alias, $aliases)) {
                $this->fail_('routes', "Middleware alias [{$alias}] is not registered in bootstrap/app.php.");
            }
        }

        $this->components->twoColumnDetail(
            'Routes',
            (count($required) - count($missing)).' of '.count($required).' registered',
        );
    }

    /** Relations that later layers depend on but earlier layers defined the model without. */
    private function checkRelations(): void
    {
        $required = [
            \App\Models\Enrollment::class => ['notes'],
            \App\Models\Batch::class => ['enrollmentsForAttendance'],
            \App\Models\Subscription::class => ['pendingPlan'],
            \App\Models\Learner::class => ['guardians', 'enrollments'],
        ];

        $missing = 0;

        foreach ($required as $class => $methods) {
            foreach ($methods as $method) {
                if (! method_exists($class, $method)) {
                    $missing++;
                    $this->fail_(
                        'models',
                        class_basename($class)."::{$method}() is missing — a later layer calls it.",
                    );
                }
            }
        }

        $this->components->twoColumnDetail('Model relations', $missing === 0 ? 'all present' : $missing.' missing');
    }

    /**
     * Seeders have a strict order: vocabularies before the records that reference them.
     */
    private function checkSeederOrder(): void
    {
        $path = database_path('seeders/DatabaseSeeder.php');

        if (! File::exists($path)) {
            $this->warn_('seeders', 'DatabaseSeeder.php not found.');

            return;
        }

        $source = File::get($path);
        $order = ['RolesAndPermissionsSeeder', 'PresetApplier', 'AcademicSeeder', 'PeopleSeeder',
            'AttendanceSeeder', 'GradingSeeder', 'ReportingSeeder'];

        $positions = [];

        foreach ($order as $seeder) {
            $position = strpos($source, $seeder);

            if ($position === false) {
                $this->warn_('seeders', "DatabaseSeeder does not call {$seeder}.");

                continue;
            }

            $positions[$seeder] = $position;
        }

        $sorted = $positions;
        asort($sorted);

        if (array_keys($sorted) !== array_keys($positions)) {
            $this->fail_(
                'seeders',
                'Seeders are called out of order. Vocabularies must be seeded before the records '
                .'that reference them: '.implode(' → ', $order),
            );
        }

        $this->components->twoColumnDetail('Seeder order', count($positions).' of '.count($order).' present');
    }

    private function report(): int
    {
        $failures = array_filter($this->findings, fn (array $f) => $f['level'] === 'fail');
        $warnings = array_filter($this->findings, fn (array $f) => $f['level'] === 'warn');

        $this->newLine();

        foreach ($this->findings as $finding) {
            $finding['level'] === 'fail'
                ? $this->components->error("[{$finding['area']}] {$finding['message']}")
                : $this->components->warn("[{$finding['area']}] {$finding['message']}");
        }

        if ($failures === [] && ($warnings === [] || ! $this->option('strict'))) {
            $this->newLine();
            $this->components->info('Everything is wired up.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->error(sprintf(
            '%d problem(s) and %d warning(s). Fix these before deploying.',
            count($failures),
            count($warnings),
        ));

        return self::FAILURE;
    }

    private function fail_(string $area, string $message): void
    {
        $this->findings[] = ['level' => 'fail', 'area' => $area, 'message' => $message];
    }

    private function warn_(string $area, string $message): void
    {
        $this->findings[] = ['level' => 'warn', 'area' => $area, 'message' => $message];
    }
}
