<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A closure date for one branch. Session generation skips these and says what it skipped.
 */
final class Holiday extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'branch_id', 'date', 'name', 'blocks_sessions'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'blocks_sessions' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function auditModule(): string
    {
        return 'Organisation';
    }
}
