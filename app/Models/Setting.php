<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One configuration value at one scope level. Read through SettingsResolver, never directly.
 */
final class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'key', 'value', 'scope_type', 'scope_id', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
