<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TerminologyOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TerminologyOverride> */
final class TerminologyOverrideFactory extends Factory
{
    protected $model = TerminologyOverride::class;

    public function definition(): array
    {
        return [
            'term_key' => 'batch',
            'singular' => 'Cohort',
            'plural' => 'Cohorts',
        ];
    }
}
