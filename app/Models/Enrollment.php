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

/**
 * One learner in one batch — the anchor for every academic record that follows.
 */
final class Enrollment extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'learner_id', 'batch_id', 'number', 'enrolled_on',
        'status_id', 'status_reason', 'ended_on', 'transferred_to_enrollment_id',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_on' => 'immutable_date',
            'ended_on' => 'immutable_date',
        ];
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(EnrollmentStatus::class, 'status_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(EnrollmentStatusHistory::class)->orderBy('changed_at');
    }

    public function transferredTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_to_enrollment_id');
    }

    /** Enrollments the metering job counts. */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $q) => $q->where('is_active_for_billing', true));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $q) => $q->where('is_terminal', false));
    }

    public function auditModule(): string
    {
        return 'People';
    }

    public function auditLabel(): ?string
    {
        return $this->number;
    }

    public function notes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TeacherNote::class);
    }
}