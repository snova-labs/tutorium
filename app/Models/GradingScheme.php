<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GradingSchemeKind;
use App\Support\Audit\Auditable;
use App\Support\Grading\GradingRegistry;
use App\Support\Grading\GradingStrategy;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * How a piece of work is scored.
 *
 * The `config` payload is whatever the kind needs — a maximum, a set of letter bands, a level
 * ladder — so adding a scale is data entry rather than a migration.
 */
final class GradingScheme extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'name', 'code', 'kind', 'config', 'is_active'];

    protected function casts(): array
    {
        return [
            'kind' => GradingSchemeKind::class,
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function strategy(): GradingStrategy
    {
        return app(GradingRegistry::class)->for($this->kind);
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}
