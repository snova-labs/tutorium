<?php

declare(strict_types=1);

namespace App\Models;

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
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'two_factor_secret',
        'two_factor_confirmed_at', 'is_active', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

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
