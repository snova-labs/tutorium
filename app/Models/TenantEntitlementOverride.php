<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\TenantEntitlementOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A negotiated exception, with the reason it was granted and when it lapses. */
final class TenantEntitlementOverride extends Model
{
    /** @use HasFactory<TenantEntitlementOverrideFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'feature_key', 'value', 'reason', 'granted_by_operator_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['value' => 'array', 'expires_at' => 'immutable_datetime'];
    }

    public function isCurrent(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
