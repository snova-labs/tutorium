<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'name' => 'Sample Branch',
            'code' => strtoupper(fake()->unique()->bothify('BC##??')),
            'timezone' => 'UTC',
            'week_start' => 'monday',
            'weekend_days' => ['saturday', 'sunday'],
            'is_active' => true,
        ];
    }

    /** A branch whose local rules differ from the defaults — used by localization tests. */
    public function gulf(): self
    {
        return $this->state(fn () => [
            'timezone' => 'Asia/Dubai',
            'week_start' => 'sunday',
            'weekend_days' => ['friday', 'saturday'],
        ]);
    }

    /** A daylight-saving timezone — used by session generation tests. */
    public function northAmerica(): self
    {
        return $this->state(fn () => [
            'timezone' => 'America/Toronto',
            'week_start' => 'sunday',
        ]);
    }
}
