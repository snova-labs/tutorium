<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Guardian;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Guardian> */
final class GuardianFactory extends Factory
{
    protected $model = Guardian::class;

    public function definition(): array
    {
        return [
            'name' => 'Sample Guardian '.fake()->unique()->numberBetween(1, 9999),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+977 98'.fake()->unique()->numerify('########'),
            'preferred_channel' => 'email',
            'locale' => 'en',
        ];
    }
}
