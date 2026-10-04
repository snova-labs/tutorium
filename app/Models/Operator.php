<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OperatorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A member of the platform team.
 *
 * Individually named, never shared. An account that several people use is an account whose
 * actions cannot be attributed, and attribution is the entire value of the access trail.
 */
final class Operator extends Authenticatable
{
    /** @use HasFactory<OperatorFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'two_factor_secret', 'two_factor_confirmed_at',
        'two_factor_recovery_codes', 'two_factor_last_step', 'is_active', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            // Encrypted at rest: the secret is a standing way to produce valid codes.
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'immutable_datetime',
            // Hashes only, never the codes themselves.
            'two_factor_recovery_codes' => 'array',
            'two_factor_last_step' => 'integer',
            'last_login_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Impersonation, $this> */
    public function impersonations(): HasMany
    {
        return $this->hasMany(Impersonation::class);
    }

    /** Two-factor is not optional here, so sign-in is incomplete without it. */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function canSignIn(): bool
    {
        return $this->is_active && $this->hasTwoFactor();
    }
}
