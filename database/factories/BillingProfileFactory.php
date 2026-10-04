<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BillingProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BillingProfile> */
final class BillingProfileFactory extends Factory
{
    protected $model = BillingProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => 'manual',
            'legal_name' => 'Sample Academy Ltd',
            'billing_email' => 'billing@sample.test',
        ];
    }
}
