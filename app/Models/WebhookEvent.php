<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A received provider event.
 *
 * Recorded before it is acted on, and keyed on the provider's own id. Payment providers retry, and
 * a payment applied twice is a worse failure than one applied late.
 */
final class WebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider', 'event_id', 'type', 'payload', 'received_at', 'processed_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
