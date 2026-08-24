<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GradingSchemeKind;
use App\Models\GradingScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GradingScheme> */
final class GradingSchemeFactory extends Factory
{
    protected $model = GradingScheme::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Points',
            'code' => 'SCH'.fake()->unique()->numberBetween(1, 9999),
            'kind' => GradingSchemeKind::Points,
            'config' => [],
            'is_active' => true,
        ];
    }

    public function rubric(): self
    {
        return $this->state(fn () => ['kind' => GradingSchemeKind::Rubric, 'name' => 'Rubric']);
    }

    public function passFail(): self
    {
        return $this->state(fn () => [
            'kind' => GradingSchemeKind::PassFail, 'name' => 'Pass / fail',
            'config' => ['pass_value' => 100, 'fail_value' => 0],
        ]);
    }

    /** A scale where 1 is best — the case that catches direction assumptions. */
    public function germanScale(): self
    {
        return $this->state(fn () => [
            'kind' => GradingSchemeKind::Letter, 'name' => 'German 1–6',
            'config' => ['bands' => [
                ['label' => '1', 'value' => 100], ['label' => '2', 'value' => 80],
                ['label' => '3', 'value' => 65], ['label' => '4', 'value' => 50],
                ['label' => '5', 'value' => 25], ['label' => '6', 'value' => 0],
            ]],
        ]);
    }

    public function cefr(): self
    {
        return $this->state(fn () => [
            'kind' => GradingSchemeKind::Level, 'name' => 'CEFR',
            'config' => ['ladder' => ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']],
        ]);
    }
}
