<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which sections a report carries, and in what order.
 *
 * Resolution is course → brand → tenant default, so one academy can send a kids report with
 * engagement stars and a certification report without them, from the same code.
 */
final class ReportTemplate extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'brand_id', 'course_id', 'name', 'blocks', 'locale', 'closing', 'is_default',
    ];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'is_default' => 'boolean'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function shows(string $block): bool
    {
        return in_array($block, $this->blocks ?? [], true);
    }

    public function auditModule(): string
    {
        return 'Reporting';
    }
}
