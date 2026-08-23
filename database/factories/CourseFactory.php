<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PeriodType;
use App\Models\Brand;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Course> */
final class CourseFactory extends Factory
{
    protected $model = Course::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'name' => 'Sample Course',
            'code' => strtoupper(fake()->unique()->bothify('CRS##??')),
            'audience' => 'kids',
            'period_type' => PeriodType::Monthly,
            'period_anchor_month' => 1,
            'period_block_weeks' => 4,
            'is_active' => true,
        ];
    }

    public function termly(): self
    {
        return $this->state(fn () => ['period_type' => PeriodType::Term, 'audience' => 'adults']);
    }

    public function quarterly(int $anchorMonth = 4): self
    {
        return $this->state(fn () => [
            'period_type' => PeriodType::Quarter,
            'period_anchor_month' => $anchorMonth,
        ]);
    }

    public function blocks(int $weeks = 4): self
    {
        return $this->state(fn () => [
            'period_type' => PeriodType::Block,
            'period_block_weeks' => $weeks,
        ]);
    }
}
