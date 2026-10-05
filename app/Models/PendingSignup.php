<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A signup form waiting for its address to be confirmed.
 *
 * Nothing is provisioned from it until the emailed link is used (SL-402). It is global by nature:
 * there is no tenant yet, and it is only ever found by the hash of its own token or by its address.
 */
final class PendingSignup extends Model
{
    protected $fillable = ['email', 'token_hash', 'payload', 'ip', 'expires_at'];

    protected $hidden = ['token_hash', 'payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
