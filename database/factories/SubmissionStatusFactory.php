<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SubmissionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionStatus> */
final class SubmissionStatusFactory extends Factory
{
    protected $model = SubmissionStatus::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Submitted',
            'code' => 'SUB'.fake()->unique()->numberBetween(1, 9999),
            'counts_as_submitted' => true,
            'excluded_from_average' => false,
            'is_negative' => false,
            'sort' => 0,
        ];
    }

    /** Not handed in: scores zero and stays in the denominator. */
    public function missing(): self
    {
        return $this->state(fn () => [
            'name' => 'Missing', 'code' => 'MISSING',
            'counts_as_submitted' => false, 'is_negative' => true,
        ]);
    }

    /** Excused: leaves the denominator entirely. */
    public function exempt(): self
    {
        return $this->state(fn () => [
            'name' => 'Exempt', 'code' => 'EXEMPT',
            'counts_as_submitted' => false, 'excluded_from_average' => true,
        ]);
    }
}
