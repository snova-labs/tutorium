<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Homework, Classwork, Project, Quiz, Lab — a tenant's own list.
 *
 * Replaces the two hard-coded types of the old system. Weighting lives separately, on type_weights
 * per course, because the same "Project" type may matter more in one programme than another.
 */
final class AssessmentType extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'course_id', 'name', 'code',
        'counts_in_submission_rate', 'color', 'sort', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'counts_in_submission_rate' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function weights(): HasMany
    {
        return $this->hasMany(TypeWeight::class);
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}
