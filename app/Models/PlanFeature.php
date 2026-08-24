<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One limit or flag on a plan. */
final class PlanFeature extends Model
{
    use HasFactory;

    public const HARD = 'hard';

    public const SOFT = 'soft';

    protected $fillable = ['plan_id', 'feature_key', 'value', 'enforcement'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isHard(): bool
    {
        return $this->enforcement === self::HARD;
    }
}
