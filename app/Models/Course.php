<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PeriodType;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A programme of study, holding the defaults its batches inherit.
 *
 * Adding "Cyber Security" or an adult IELTS track is a row here, not a release.
 */
final class Course extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'brand_id', 'name', 'code', 'audience', 'description',
        'period_type', 'period_anchor_month', 'period_block_weeks', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => PeriodType::class,
            'period_anchor_month' => 'integer',
            'period_block_weeks' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function sessionTypes(): HasMany
    {
        return $this->hasMany(SessionType::class);
    }

    public function reportingPeriods(): HasMany
    {
        return $this->hasMany(ReportingPeriod::class);
    }

    public function auditModule(): string
    {
        return 'Academic';
    }
}
