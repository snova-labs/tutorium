<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EnrollmentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EnrollmentStatus> */
final class EnrollmentStatusFactory extends Factory
{
    protected $model = EnrollmentStatus::class;

    public function definition(): array
    {
        return [
            'name' => 'Active',
            'code' => 'ACTIVE'.fake()->unique()->numberBetween(1, 9999),
            'is_active_for_billing' => true,
            'is_terminal' => false,
            'sort' => 0,
        ];
    }

    public function withdrawn(): self
    {
        return $this->state(fn () => [
            'name' => 'Withdrawn', 'code' => 'WITHDRAWN',
            'is_active_for_billing' => false, 'is_terminal' => true,
        ]);
    }
}
