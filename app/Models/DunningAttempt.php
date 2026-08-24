<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to collect one invoice. */
final class DunningAttempt extends Model
{
    use BelongsToTenant, HasFactory;

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = [
        'tenant_id', 'invoice_id', 'attempt', 'attempted_at', 'outcome',
        'provider', 'failure_code', 'failure_message', 'next_attempt_at', 'notified',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'notified' => 'boolean',
            'attempt' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
