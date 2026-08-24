<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Present, Late, Absent, Excused — and whatever else a tenant needs.
 *
 * Four flags rather than one enum, because each answers a different question and academies
 * genuinely disagree about the answers. "Left early" counts as attended at one centre and not at
 * another, and neither is wrong.
 */
final class AttendanceStatus extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'counts_as_attended', 'counts_in_rate',
        'is_late', 'is_negative', 'color', 'sort', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'counts_as_attended' => 'boolean',
            'counts_in_rate' => 'boolean',
            'is_late' => 'boolean',
            'is_negative' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}
