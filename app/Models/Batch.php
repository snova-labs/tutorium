<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BatchStatus;
use App\Enums\DeliveryMode;
use App\Enums\PeriodType;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\BatchFactory;
use DateTimeZone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A scheduled run of a course, and the authoritative timezone for everything beneath it.
 *
 * A batch taught from Kathmandu to families in Toronto keeps Toronto time, because that is the
 * clock the people attending read (SL-LOC-005 §2).
 */
final class Batch extends Model
{
    /** @use HasFactory<BatchFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'course_id', 'branch_id', 'name', 'code', 'timezone',
        'starts_on', 'ends_on', 'capacity', 'delivery_mode', 'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'capacity' => 'integer',
            'delivery_mode' => DeliveryMode::class,
            'status' => BatchStatus::class,
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function teachers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'batch_teacher')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<TimetableSlot, $this> */
    public function timetableSlots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class);
    }

    /** @return HasMany<ClassSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    /** @return HasMany<ReportingPeriod, $this> */
    public function reportingPeriods(): HasMany
    {
        return $this->hasMany(ReportingPeriod::class);
    }

    public function zone(): DateTimeZone
    {
        return new DateTimeZone($this->timezone);
    }

    /** "Now" as the people in this batch would read it off a wall. */
    public function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now($this->zone());
    }

    public function periodType(): PeriodType
    {
        return $this->course->period_type;
    }

    public function auditModule(): string
    {
        return 'Academic';
    }

    /**
     * Enrollments that should appear on a register or a summary.
     *
     * @return Collection<int, Enrollment>
     */
    public function enrollmentsForAttendance(): Collection
    {
        return $this->hasMany(Enrollment::class)->with('learner')->active()->get();
    }
}
