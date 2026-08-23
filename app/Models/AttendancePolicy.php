<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An override of the attendance rules at course or batch level.
 *
 * Stored as whole policies rather than individual settings keys, so a partial override cannot
 * produce an incoherent combination — late_grace_min without allow_late_join means nothing
 * (SL-ARC-002 §7).
 */
final class AttendancePolicy extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $table = 'attendance_policies';

    protected $fillable = [
        'tenant_id', 'scope_type', 'scope_id', 'is_compulsory', 'allow_late_join',
        'late_grace_min', 'counted_session_type_ids', 'low_threshold_pct',
    ];

    protected function casts(): array
    {
        return [
            'is_compulsory' => 'boolean',
            'allow_late_join' => 'boolean',
            'late_grace_min' => 'integer',
            'counted_session_type_ids' => 'array',
            'low_threshold_pct' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}
