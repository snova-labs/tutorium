<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A trading identity within a tenant: logo, colours, report template, sender identity.
 *
 * Branding lives here rather than on the tenant, so a group operating several school names under
 * one account reports correctly under each. Most tenants have exactly one, created automatically
 * and hidden until multi-brand is enabled.
 */
final class Brand extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'logo_path', 'colors',
        'sender_name', 'sender_email', 'domain', 'locale', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function auditModule(): string
    {
        return 'Organisation';
    }
}
