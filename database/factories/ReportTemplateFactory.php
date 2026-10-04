<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ReportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportTemplate> */
final class ReportTemplateFactory extends Factory
{
    protected $model = ReportTemplate::class;

    public function definition(): array
    {
        return [
            'name' => 'Standard report',
            'blocks' => [['type' => 'attendance'], ['type' => 'grades']],
        ];
    }
}
