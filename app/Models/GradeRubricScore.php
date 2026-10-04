<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\GradeRubricScoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One criterion's score within a rubric grade. */
final class GradeRubricScore extends Model
{
    /** @use HasFactory<GradeRubricScoreFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'grade_id', 'rubric_criterion_id', 'points', 'comment'];

    protected function casts(): array
    {
        return ['points' => 'decimal:2'];
    }

    /** @return BelongsTo<Grade, $this> */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    /** @return BelongsTo<RubricCriterion, $this> */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'rubric_criterion_id');
    }
}
