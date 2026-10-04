<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SessionTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What kind of occurrence a session is: Class, Lab, Workshop, Make-up.
 *
 * A lookup rather than an enum, because the set differs per academy and per course — the whole
 * point of replacing the old fixed Saturday/Hangout pair.
 */
final class SessionType extends Model
{
    /** @use HasFactory<SessionTypeFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'course_id', 'name', 'code', 'counts_in_attendance', 'color', 'sort', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'counts_in_attendance' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}
