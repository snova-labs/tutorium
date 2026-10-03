<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TenantExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantExport> */
final class TenantExportFactory extends Factory
{
    protected $model = TenantExport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'status' => TenantExport::STATUS_QUEUED,
        ];
    }
}
