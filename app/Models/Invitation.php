<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An offer of access, which grants nothing until it is accepted.
 *
 * That distinction matters more than it sounds: an invitation that pre-creates a user account is
 * an account that exists, appears in lists, and can be counted or mistaken for a colleague who has
 * actually joined (FR-IAM-5).
 */
final class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'email', 'name', 'token_hash', 'role_name',
        'scope_all_branches', 'branch_ids', 'invited_by',
        'expires_at', 'accepted_at', 'accepted_user_id', 'revoked_at', 'send_count',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'branch_ids' => 'array',
            'scope_all_branches' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'send_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    public function auditModule(): string
    {
        return 'People';
    }

    public function auditLabel(): string
    {
        return $this->email;
    }

    /**
     * A token in the activity log would be a working invitation in the activity log.
     *
     * @return array<int, string>
     */
    public function auditExcluded(): array
    {
        return ['token_hash', 'created_at', 'updated_at'];
    }
}
