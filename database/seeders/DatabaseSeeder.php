<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Operator;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development data — canonical.
 *
 * Two tenants, always. A single-tenant development database hides exactly the class of bug this
 * architecture exists to prevent, and the two are given different presets, different timezones and
 * different identifier formats so that anything accidentally hard-coded shows up immediately.
 *
 * Order matters and is enforced by `platform:verify`: vocabularies before the records that
 * reference them.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);
        $this->operator();

        // Kathmandu: +05:45, no daylight saving, Saturday weekend, Sunday week start.
        $one = $this->tenant('Sample Academy One', 'owner@sample-one.test', 'Asia/Kathmandu', [
            'preset_code' => 'kids-tutoring-south-asia',
            'week_start' => 'sunday',
            'weekend_days' => ['saturday'],
        ]);

        // Dubai: Friday–Saturday weekend, adult learners, no guardians. Nothing shared with the
        // first account except the code that serves them both.
        $two = $this->tenant('Sample Language School', 'owner@sample-two.test', 'Asia/Dubai', [
            'preset_code' => 'language-school-gulf',
            'week_start' => 'sunday',
            'weekend_days' => ['friday', 'saturday'],
        ]);

        $this->populate($one);

        $this->command?->newLine();
        $this->command?->info('Sign in as owner@sample-one.test or owner@sample-two.test — password: password');
        $this->command?->info('Operator console: operator@snova-labs.test — password: password');
    }

    /** @param array<string, mixed> $options */
    private function tenant(string $name, string $email, string $timezone, array $options): Tenant
    {
        $existing = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::query()->where('contact_email', $email)->first()
        );

        if ($existing !== null) {
            return $existing;
        }

        $result = app(TenantProvisioner::class)->provision(array_merge([
            'name' => $name,
            'owner_name' => 'Sample Owner',
            'owner_email' => $email,
            'password' => 'password',
            'timezone' => $timezone,
            'status' => Tenant::STATUS_ACTIVE,
        ], $options));

        $plan = Plan::query()->where('code', 'growth')->first();

        if ($plan !== null) {
            app(SubscriptionService::class)->subscribe($result['tenant'], $plan);
        }

        return $result['tenant'];
    }

    /**
     * Academic data for the first account only.
     *
     * The second stays empty on purpose, so that every isolation check has a tenant with nothing
     * in it to compare against.
     */
    private function populate(Tenant $tenant): void
    {
        app(TenantContext::class)->runAs($tenant, function () use ($tenant): void {
            app(AcademicSeeder::class)->setContainer(app())->setCommand($this->command)->run($tenant);
            app(PeopleSeeder::class)->setContainer(app())->setCommand($this->command)->run($tenant);
            app(AttendanceSeeder::class)->setContainer(app())->setCommand($this->command)->run($tenant);
            app(GradingSeeder::class)->setContainer(app())->setCommand($this->command)->run($tenant);
            app(ReportingSeeder::class)->setContainer(app())->setCommand($this->command)->run($tenant);
        });
    }

    private function operator(): void
    {
        Operator::query()->firstOrCreate(
            ['email' => 'operator@snova-labs.test'],
            [
                'name' => 'Sample Operator',
                'password' => Hash::make('password'),
                // A placeholder until real enrolment is built, so the guard stays honest without
                // pretending two-factor is implemented.
                'two_factor_secret' => encrypt('PLACEHOLDER'),
                'two_factor_confirmed_at' => now(),
                'is_active' => true,
            ],
        );
    }
}