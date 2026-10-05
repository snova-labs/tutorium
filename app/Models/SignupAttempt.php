<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A record of one attempt to create an account.
 *
 * Exists only to make rate limiting possible and is purged after a fortnight. A permanent log of
 * who considered signing up is data with no purpose and a real cost if it leaks.
 */
final class SignupAttempt extends Model
{
    /** The form was accepted and a confirmation link sent. Nothing is provisioned yet. */
    public const PENDING = 'pending';

    public const CREATED = 'created';

    public const RATE_LIMITED = 'rate_limited';

    public const REJECTED = 'rejected';

    protected $fillable = ['email', 'domain', 'ip', 'outcome', 'reason', 'attempted_at'];

    protected function casts(): array
    {
        return ['attempted_at' => 'immutable_datetime'];
    }
}
