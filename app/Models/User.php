<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A login identity.
 *
 * Deliberately separate from person records: guardians and learners exist as people with no
 * account, and are granted a login later by linking rather than duplicating (SL-ARC-002 §8).
 * That separation is what makes the P5 portals a feature rather than a migration.
 */
final class User extends Authenticatable
{
    use BelongsToTenant, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'password', 'timezone', 'locale',
        'is_active', 'scope_all_branches', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'scope_all_branches' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Branch scope. Empty with scope_all_branches = true means every branch. */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withTimestamps();
    }

    /**
     * Branch scope check. Authorization policies call this; it is never the only control —
     * queries are already tenant-scoped underneath (SL-SEC-004 §3).
     */
    public function canAccessBranch(int $branchId): bool
    {
        return $this->scope_all_branches
            || $this->branches()->whereKey($branchId)->exists();
    }
}
