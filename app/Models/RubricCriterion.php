<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a rubric: what is being judged, and out of how much. */
final class RubricCriterion extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'assessment_id', 'name', 'descriptor', 'max_points', 'sort'];

    protected function casts(): array
    {
        return ['max_points' => 'decimal:2', 'sort' => 'integer'];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function auditModule(): string
    {
        return 'Grading';
    }
}
