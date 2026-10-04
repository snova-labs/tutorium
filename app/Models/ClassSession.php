<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SessionStatus;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ClassSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One occurrence of a class.
 *
 * Named ClassSession rather than Session because the framework already owns that word — see the
 * note in the academic migration.
 */
final class ClassSession extends Model
{
    /** @use HasFactory<ClassSessionFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'class_sessions';

    protected $fillable = [
        'tenant_id', 'batch_id', 'session_type_id', 'session_local_date', 'start_time_local',
        'starts_at_utc', 'ends_at_utc', 'status', 'cancel_reason',
        'meeting_url', 'meeting_provider_ref', 'generated_from_slot_id',
    ];

    protected function casts(): array
    {
        return [
            'session_local_date' => 'immutable_date',
            'starts_at_utc' => 'immutable_datetime',
            'ends_at_utc' => 'immutable_datetime',
            'status' => SessionStatus::class,
        ];
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** @return BelongsTo<SessionType, $this> */
    public function sessionType(): BelongsTo
    {
        return $this->belongsTo(SessionType::class);
    }

    /** @return BelongsTo<TimetableSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(TimetableSlot::class, 'generated_from_slot_id');
    }

    /** The start instant rendered in whichever zone the reader cares about. */
    public function startsAtIn(string $timezone): CarbonImmutable
    {
        return $this->starts_at_utc->setTimezone($timezone);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeBetweenUtc(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->whereBetween('starts_at_utc', [$from, $to]);
    }

    /**
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeCountingTowardAttendance(Builder $query): Builder
    {
        return $query->where('status', SessionStatus::Held)
            ->whereHas('sessionType', fn (Builder $q) => $q->where('counts_in_attendance', true));
    }

    public function auditModule(): string
    {
        return 'Scheduling';
    }

    public function auditLabel(): string
    {
        return $this->session_local_date->toDateString().' '.substr((string) $this->start_time_local, 0, 5);
    }
}
