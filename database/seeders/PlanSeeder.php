<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

/**
 * The plan catalogue.
 *
 * Prices here are placeholders pending validation with design partners (SL-PRD-000 D4). The shape
 * is what matters at this point: per active learner, with a minimum that makes a very small
 * academy viable to serve.
 */
final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'starter', 'name' => 'Starter', 'unit' => 200, 'minimum' => 4000, 'sort' => 0,
                'features' => [
                    ['max_brands', 1, PlanFeature::HARD],
                    ['max_branches', 2, PlanFeature::HARD],
                    ['max_staff', 5, PlanFeature::SOFT],
                    ['api_access', false, PlanFeature::SOFT],
                    ['custom_domain', false, PlanFeature::SOFT],
                    ['ms_integrations', false, PlanFeature::SOFT],
                    ['sso', false, PlanFeature::SOFT],
                ],
            ],
            [
                'code' => 'growth', 'name' => 'Growth', 'unit' => 175, 'minimum' => 9000, 'sort' => 1,
                'features' => [
                    ['max_brands', 5, PlanFeature::HARD],
                    ['max_branches', null, PlanFeature::SOFT],
                    ['max_staff', 25, PlanFeature::SOFT],
                    ['api_access', true, PlanFeature::SOFT],
                    ['custom_domain', true, PlanFeature::SOFT],
                    ['ms_integrations', true, PlanFeature::SOFT],
                    ['scheduled_reports', true, PlanFeature::SOFT],
                    ['sso', false, PlanFeature::SOFT],
                ],
            ],
            [
                'code' => 'institution', 'name' => 'Institution', 'unit' => 150, 'minimum' => 0, 'sort' => 2,
                'features' => [
                    ['max_brands', null, PlanFeature::SOFT],
                    ['max_branches', null, PlanFeature::SOFT],
                    ['max_staff', null, PlanFeature::SOFT],
                    ['api_access', true, PlanFeature::SOFT],
                    ['custom_domain', true, PlanFeature::SOFT],
                    ['ms_integrations', true, PlanFeature::SOFT],
                    ['scheduled_reports', true, PlanFeature::SOFT],
                    ['sso', true, PlanFeature::SOFT],
                    ['data_residency', true, PlanFeature::SOFT],
                ],
            ],
            [
                'code' => 'self-hosted', 'name' => 'Self-hosted licence', 'unit' => 0, 'minimum' => 0,
                'sort' => 3, 'public' => false,
                'features' => [
                    ['max_brands', null, PlanFeature::SOFT],
                    ['api_access', true, PlanFeature::SOFT],
                    ['ms_integrations', true, PlanFeature::SOFT],
                ],
            ],
        ];

        foreach ($plans as $definition) {
            $plan = Plan::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'currency' => 'EUR',
                    'unit_price_minor' => $definition['unit'],
                    'minimum_charge_minor' => $definition['minimum'],
                    'interval' => 'month',
                    'is_public' => $definition['public'] ?? true,
                    'sort' => $definition['sort'],
                ],
            );

            foreach ($definition['features'] as [$key, $value, $enforcement]) {
                PlanFeature::query()->updateOrCreate(
                    ['plan_id' => $plan->getKey(), 'feature_key' => $key],
                    ['value' => ['value' => $value], 'enforcement' => $enforcement],
                );
            }
        }

        $this->command?->info('Seeded '.count($plans).' plans. Prices are placeholders.');
    }
}
