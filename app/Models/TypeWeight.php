<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\TypeWeightFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much an assessment type counts toward a course's period average.
 *
 * Per course rather than global: a project may be worth 40% of a kids programme and 70% of a
 * certification track, and both belong to the same tenant.
 */
final class TypeWeight extends Model
{
    /** @use HasFactory<TypeWeightFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'course_id', 'assessment_type_id', 'weight_pct'];

    protected function casts(): array
    {
        return ['weight_pct' => 'decimal:2'];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<AssessmentType, $this> */
    public function assessmentType(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class);
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}
