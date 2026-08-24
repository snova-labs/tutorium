<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Operator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<Operator> */
final class OperatorFactory extends Factory
{
    protected $model = Operator::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Sample Operator',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            // Two-factor confirmed by default, because an operator without it cannot sign in and
            // most tests are not about that rule.
            'two_factor_secret' => encrypt('SAMPLESECRET'),
            'two_factor_confirmed_at' => now(),
            'is_active' => true,
        ];
    }

    public function withoutTwoFactor(): self
    {
        return $this->state(fn () => ['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);
    }
}
