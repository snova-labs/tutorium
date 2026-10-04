<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SampleDataSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A manifest of everything one sample-data load created, so removal is exact. */
final class SampleDataSet extends Model
{
    /** @use HasFactory<SampleDataSetFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'label', 'created', 'loaded_by', 'loaded_at', 'removed_at'];

    protected function casts(): array
    {
        return [
            'created' => 'array',
            'loaded_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }
}
