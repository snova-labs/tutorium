<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
final class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'actor_type' => AuditLog::ACTOR_USER,
            'actor_id' => null,
            'actor_name' => 'Sample Staff Member',
            'module' => 'Organisation',
            'action' => 'updated',
            'auditable_type' => 'brand',
            'auditable_id' => 1,
            'target_label' => 'Sample Brand',
            'before' => ['name' => 'Old name'],
            'after' => ['name' => 'New name'],
            'ip' => '127.0.0.1',
            'occurred_at' => now(),
        ];
    }
}
