<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A piece of gradable work set for one batch. */
final class Assessment extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'batch_id', 'assessment_type_id', 'grading_scheme_id', 'number',
        'title', 'description', 'assigned_local_date', 'due_local_date', 'due_at_utc',
        'max_points', 'is_published',
    ];

    protected function casts(): array
    {
        return [
            'assigned_local_date' => 'immutable_date',
            'due_local_date' => 'immutable_date',
            'due_at_utc' => 'immutable_datetime',
            'max_points' => 'decimal:2',
            'is_published' => 'boolean',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function assessmentType(): BelongsTo
    {
        return $this->belongsTo(AssessmentType::class);
    }

    public function gradingScheme(): BelongsTo
    {
        return $this->belongsTo(GradingScheme::class);
    }

    public function rubricCriteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class)->orderBy('sort');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function auditModule(): string
    {
        return 'Grading';
    }

    public function auditLabel(): ?string
    {
        return $this->title;
    }
}
