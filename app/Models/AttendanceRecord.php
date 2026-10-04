<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\AttendanceRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One learner's mark for one session. */
final class AttendanceRecord extends Model
{
    /** @use HasFactory<AttendanceRecordFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'class_session_id', 'enrollment_id', 'status_id',
        'minutes_late', 'note', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'minutes_late' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ClassSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<AttendanceStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(AttendanceStatus::class, 'status_id');
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }

    public function auditLabel(): ?string
    {
        return $this->enrollment?->number;
    }
}
