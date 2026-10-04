<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SampleDataSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SampleDataSet> */
final class SampleDataSetFactory extends Factory
{
    protected $model = SampleDataSet::class;

    public function definition(): array
    {
        return [
            'label' => 'Sample data',
            'created' => [],
            'loaded_at' => now(),
        ];
    }
}
