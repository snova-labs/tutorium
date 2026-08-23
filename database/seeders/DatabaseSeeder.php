<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\IdSequence;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development seed data.
 *
 * Two tenants, always — a single-tenant development database hides exactly the class of bug this
 * architecture exists to prevent. Names are neutral by policy: no customer or learner name ever
 * appears in code, seeds or fixtures.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(TenantContext::class);

        $one = $context->withoutScoping(fn () => Tenant::query()->firstOrCreate(
            ['slug' => 'sample-one'],
            [
                'name' => 'Sample Academy One',
                'status' => Tenant::STATUS_ACTIVE,
                'contact_email' => 'owner@sample-one.test',
                'preset_code' => 'kids-tutoring-south-asia',
                'region_code' => 'default',
                'deployment_mode' => 'cloud',
                'locale' => 'en',
            ],
        ));

        $two = $context->withoutScoping(fn () => Tenant::query()->firstOrCreate(
            ['slug' => 'sample-two'],
            [
                'name' => 'Sample Academy Two',
                'status' => Tenant::STATUS_ACTIVE,
                'contact_email' => 'owner@sample-two.test',
                'preset_code' => 'language-school-gulf',
                'region_code' => 'default',
                'deployment_mode' => 'cloud',
                'locale' => 'en',
            ],
        ));

        $this->seedTenantOne($one);
        $this->seedTenantTwo($two);

        $this->command?->newLine();
        $this->command?->info('Sign in with owner@sample-one.test or owner@sample-two.test — password: password');
        $this->call(AcademicSeeder::class);
        
    }

    private function seedTenantOne(Tenant $tenant): void
    {
        app(TenantContext::class)->runAs($tenant, function () use ($tenant): void {
            $this->callWith(RolesAndPermissionsSeeder::class, ['tenant' => $tenant]);

            $brand = Brand::query()->firstOrCreate(
                ['code' => 'ONE'],
                ['name' => 'Sample Brand One', 'is_default' => true, 'locale' => 'en',
                    'sender_name' => 'Sample Brand One', 'sender_email' => 'reports@sample-one.test'],
            );

            Branch::query()->firstOrCreate(
                ['code' => 'KTM'],
                [
                    'brand_id' => $brand->id,
                    'name' => 'Head Office',
                    'timezone' => 'Asia/Kathmandu',
                    'week_start' => 'sunday',
                    'weekend_days' => ['saturday'],
                    'is_active' => true,
                ],
            );

            // A second branch in a daylight-saving timezone, so local development exercises the
            // cross-timezone case by default rather than only in tests.
            Branch::query()->firstOrCreate(
                ['code' => 'ONL'],
                [
                    'brand_id' => $brand->id,
                    'name' => 'Online — Americas',
                    'timezone' => 'America/Toronto',
                    'week_start' => 'sunday',
                    'weekend_days' => ['saturday', 'sunday'],
                    'is_active' => true,
                ],
            );

            IdSequence::query()->firstOrCreate(
                ['entity' => 'learner', 'scope_type' => 'tenant', 'scope_id' => null],
                ['prefix' => 'ONE-STU', 'separator' => '-', 'pad_width' => 4, 'next_number' => 1, 'is_active' => true],
            );

            $this->owner($tenant, 'owner@sample-one.test', 'Sample Owner');
            $this->staff('front-desk@sample-one.test', 'Sample Front Desk', 'Front desk');
            $this->staff('teacher@sample-one.test', 'Sample Teacher', 'Teacher');
        });
    }

    private function seedTenantTwo(Tenant $tenant): void
    {
        app(TenantContext::class)->runAs($tenant, function () use ($tenant): void {
            $this->callWith(RolesAndPermissionsSeeder::class, ['tenant' => $tenant]);

            $brand = Brand::query()->firstOrCreate(
                ['code' => 'TWO'],
                ['name' => 'Sample Brand Two', 'is_default' => true, 'locale' => 'en'],
            );

            Branch::query()->firstOrCreate(
                ['code' => 'GLF'],
                [
                    'brand_id' => $brand->id,
                    'name' => 'Gulf Campus',
                    'timezone' => 'Asia/Dubai',
                    'week_start' => 'sunday',
                    'weekend_days' => ['friday', 'saturday'],
                    'is_active' => true,
                ],
            );

            // Deliberately different numbering, so it is obvious in development that identifier
            // formats are per tenant rather than a global constant.
            IdSequence::query()->firstOrCreate(
                ['entity' => 'learner', 'scope_type' => 'tenant', 'scope_id' => null],
                ['prefix' => 'S', 'separator' => '', 'pad_width' => 3, 'next_number' => 6, 'is_active' => true],
            );

            $this->owner($tenant, 'owner@sample-two.test', 'Sample Owner');
        });
    }

    private function owner(Tenant $tenant, string $email, string $name): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'is_active' => true,
                'scope_all_branches' => true,
                'locale' => 'en',
            ],
        );

        $user->syncRoles([config('permissions.owner_role', 'Owner')]);

        return $user;
    }

    private function staff(string $email, string $name, string $role): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'is_active' => true,
                'scope_all_branches' => false,
                'locale' => 'en',
            ],
        );

        $user->syncRoles([$role]);

        return $user;
    }

    /** @param array<string, mixed> $parameters */
    private function callWith(string $seeder, array $parameters): void
    {
        app($seeder)->setContainer(app())->setCommand($this->command ?? app('Illuminate\Console\Command'))
            ->run(...array_values($parameters));
    }
}