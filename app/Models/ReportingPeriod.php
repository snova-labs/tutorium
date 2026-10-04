<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PeriodType;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\ReportingPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The window a report covers.
 *
 * A first-class record rather than a month string, so a term, a quarter and a six-week bootcamp
 * block are all expressible without special cases downstream.
 */
final class ReportingPeriod extends Model
{
    /** @use HasFactory<ReportingPeriodFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_REPORTED = 'reported';

    protected $fillable = [
        'tenant_id', 'course_id', 'batch_id', 'type', 'label',
        'starts_local_date', 'ends_local_date', 'status', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PeriodType::class,
            'starts_local_date' => 'immutable_date',
            'ends_local_date' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Closed periods still accept amendments — audited, and flagged on any regenerated report. */
    public function acceptsRoutineEdits(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function auditModule(): string
    {
        return 'Reporting';
    }
}
