<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\IdSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable identifier format. Consumed only through IdSequenceService, which increments it
 * atomically — reading next_number and writing it back from PHP would race.
 */
final class IdSequence extends Model
{
    /** @use HasFactory<IdSequenceFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'id_sequences';

    protected $fillable = [
        'tenant_id', 'entity', 'scope_type', 'scope_id',
        'prefix', 'separator', 'pad_width', 'next_number', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pad_width' => 'integer',
            'next_number' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
