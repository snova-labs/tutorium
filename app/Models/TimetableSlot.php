<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Weekday;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\TimetableSlotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recurring entry in a batch's weekly timetable — the template sessions are generated from.
 */
final class TimetableSlot extends Model
{
    /** @use HasFactory<TimetableSlotFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'batch_id', 'session_type_id', 'weekday',
        'start_time_local', 'duration_min', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => Weekday::class,
            'duration_min' => 'integer',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
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

    /** "HH:MM" — the wall-clock time, without seconds, as a person would write it. */
    public function localTime(): string
    {
        return substr((string) $this->start_time_local, 0, 5);
    }

    public function isEffectiveOn(\DateTimeInterface $date): bool
    {
        if ($this->effective_from !== null && $date < $this->effective_from) {
            return false;
        }

        return ! ($this->effective_to !== null && $date > $this->effective_to);
    }

    public function auditModule(): string
    {
        return 'Academic';
    }
}
