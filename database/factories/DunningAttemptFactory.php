<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DunningAttempt;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DunningAttempt> */
final class DunningAttemptFactory extends Factory
{
    protected $model = DunningAttempt::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'attempt' => 1,
            'attempted_at' => now(),
            'outcome' => DunningAttempt::FAILED,
            'provider' => 'manual',
        ];
    }
}
