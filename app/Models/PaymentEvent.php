<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A webhook we received.
 *
 * Stored before it is acted on, and keyed by the provider's own event id. Providers retry for
 * days; without this, one retry could record a payment twice or reinstate a cancelled account.
 */
final class PaymentEvent extends Model
{
    use HasFactory;

    protected $fillable = ['provider', 'event_id', 'type', 'payload', 'received_at', 'processed_at', 'error'];

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
