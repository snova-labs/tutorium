<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\EnrollmentFactory;
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
    /** @use HasFactory<EnrollmentFactory> */
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

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** @return BelongsTo<EnrollmentStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(EnrollmentStatus::class, 'status_id');
    }

    /** @return HasMany<EnrollmentStatusHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(EnrollmentStatusHistory::class)->orderBy('changed_at');
    }

    /** @return BelongsTo<self, $this> */
    public function transferredTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_to_enrollment_id');
    }

    /**
     * Enrollments the metering job counts.
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeBillable(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $q) => $q->where('is_active_for_billing', true));
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereHas('status', fn (Builder $q) => $q->where('is_terminal', false));
    }

    public function auditModule(): string
    {
        return 'People';
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    /** @return HasMany<TeacherNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(TeacherNote::class);
    }
}
