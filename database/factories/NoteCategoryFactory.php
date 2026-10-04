<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NoteCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NoteCategory> */
final class NoteCategoryFactory extends Factory
{
    protected $model = NoteCategory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Progress',
            'code' => 'progress-'.fake()->unique()->numberBetween(1, 99999),
        ];
    }
}
