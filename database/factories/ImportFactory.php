<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Import;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Import> */
final class ImportFactory extends Factory
{
    protected $model = Import::class;

    public function definition(): array
    {
        return [
            'type' => Import::TYPE_LEARNERS,
            'status' => Import::STATUS_PREVIEWED,
            'original_name' => 'learners.csv',
        ];
    }
}
