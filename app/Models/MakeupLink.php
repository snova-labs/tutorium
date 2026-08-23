<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that a missed session was made up in another one.
 *
 * Language schools need this constantly. The link is what lets a report say "attended 7 of 8,
 * with one made up" rather than showing an absence the family already resolved.
 */
final class MakeupLink extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'enrollment_id', 'missed_session_id', 'makeup_session_id', 'note'];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function missedSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'missed_session_id');
    }

    public function makeupSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'makeup_session_id');
    }

    public function auditModule(): string
    {
        return 'Attendance';
    }
}
