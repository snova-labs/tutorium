<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A record of which preset shaped a tenant, and at which version. */
final class PresetApplication extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'preset_code', 'version', 'summary', 'applied_by', 'applied_at'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'applied_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
