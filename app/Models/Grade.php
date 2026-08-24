<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One learner's result for one assessment.
 *
 * Holds the raw result in whatever terms the scheme uses, plus `normalized_pct` — the single
 * column every dashboard, trend and report reads.
 */
final class Grade extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'assessment_id', 'enrollment_id', 'submission_status_id',
        'raw_score', 'letter', 'level_code', 'passed', 'normalized_pct',
        'feedback', 'graded_by', 'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_score' => 'decimal:2',
            'normalized_pct' => 'decimal:2',
            'passed' => 'boolean',
            'graded_at' => 'immutable_datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function submissionStatus(): BelongsTo
    {
        return $this->belongsTo(SubmissionStatus::class);
    }

    public function rubricScores(): HasMany
    {
        return $this->hasMany(GradeRubricScore::class);
    }

    public function display(): string
    {
        return $this->assessment->gradingScheme->strategy()->display($this, $this->assessment);
    }

    public function auditModule(): string
    {
        return 'Grading';
    }

    public function auditLabel(): ?string
    {
        return $this->assessment?->title;
    }
}
