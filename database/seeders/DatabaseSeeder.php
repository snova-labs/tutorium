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

        $one = $context->withoutScoping(fn () => Tenant::factory()->create([
            'name' => 'Sample Academy One',
            'slug' => 'sample-one',
            'contact_email' => 'owner@sample-one.test',
        ]));

        $two = $context->withoutScoping(fn () => Tenant::factory()->create([
            'name' => 'Sample Academy Two',
            'slug' => 'sample-two',
            'contact_email' => 'owner@sample-two.test',
        ]));

        $context->runAs($one, function (): void {
            $brand = Brand::factory()->create(['name' => 'Sample Brand One', 'code' => 'ONE']);

            Branch::factory()->create([
                'brand_id' => $brand->id,
                'name' => 'Sample Branch — Head Office',
                'code' => 'HQ',
                'timezone' => 'Asia/Kathmandu',
                'week_start' => 'sunday',
                'weekend_days' => ['saturday'],
            ]);

            // A second branch in a daylight-saving timezone, so local development exercises the
            // cross-timezone case by default rather than only in tests.
            Branch::factory()->northAmerica()->create([
                'brand_id' => $brand->id,
                'name' => 'Sample Branch — Online (Americas)',
                'code' => 'ONL',
            ]);

            User::factory()->owner()->create([
                'name' => 'Sample Owner',
                'email' => 'owner@sample-one.test',
                'password' => Hash::make('password'),
            ]);

            IdSequence::factory()->create([
                'entity' => 'learner',
                'prefix' => 'ONE-STU',
                'separator' => '-',
                'pad_width' => 4,
                'next_number' => 1,
            ]);
        });

        $context->runAs($two, function (): void {
            $brand = Brand::factory()->create(['name' => 'Sample Brand Two', 'code' => 'TWO']);

            Branch::factory()->gulf()->create([
                'brand_id' => $brand->id,
                'name' => 'Sample Branch — Gulf',
                'code' => 'GLF',
            ]);

            User::factory()->owner()->create([
                'name' => 'Sample Owner',
                'email' => 'owner@sample-two.test',
                'password' => Hash::make('password'),
            ]);

            // Deliberately different numbering, to make it obvious in development that identifier
            // formats are per tenant and not a global constant.
            IdSequence::factory()->create([
                'entity' => 'learner',
                'prefix' => 'S',
                'separator' => '',
                'pad_width' => 3,
                'next_number' => 6,
            ]);
        });
    }
}
