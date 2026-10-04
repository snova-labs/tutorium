<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period of support access to one customer's account.
 *
 * Deliberately a first-class record rather than a log line: it has a reason, an expiry and an end,
 * and the customer can read it in their own activity log (FR-OPS-2, FR-AUD-3).
 */
final class Impersonation extends Model
{
    protected $fillable = [
        'operator_id', 'tenant_id', 'user_id', 'reason',
        'started_at', 'expires_at', 'ended_at', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Operator, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function minutesUsed(): int
    {
        return (int) $this->started_at->diffInMinutes($this->ended_at ?? now());
    }
}
