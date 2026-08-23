<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every status an enrollment has held, with when and why.
 *
 * Two things depend on this being rows rather than a single column: a metering run that missed a
 * day can reconstruct the count from history instead of guessing, and a billing dispute can be
 * settled by reading it.
 */
final class EnrollmentStatusHistory extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'enrollment_status_history';

    protected $fillable = [
        'tenant_id', 'enrollment_id', 'from_status_id', 'to_status_id',
        'reason', 'changed_by', 'changed_at',
    ];

    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(EnrollmentStatus::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(EnrollmentStatus::class, 'to_status_id');
    }
}
