<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in the append-only activity log.
 *
 * The model has no update or delete path by design, and the application's database account holds
 * no UPDATE or DELETE grant on this table (SL-SEC-004 §12). If a correction is ever needed, it is
 * a new entry describing the correction — never an edit of the original.
 */
final class AuditLog extends Model
{
    use BelongsToTenant, HasFactory;

    public $timestamps = false;

    public const ACTOR_USER = 'user';

    public const ACTOR_OPERATOR = 'operator';

    public const ACTOR_SYSTEM = 'system';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'actor_type', 'actor_id', 'actor_name', 'module', 'action',
        'auditable_type', 'auditable_id', 'target_label', 'before', 'after', 'ip', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Guards against a stray mass-update ever reaching this table through Eloquent. */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('Audit entries are append-only and cannot be updated.');
    }
}
