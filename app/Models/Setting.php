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
    /** Written by the preset when the account was created, rather than chosen by the academy. */
    public const ORIGIN_PRESET = 'preset';

    /** Somebody in the academy set this deliberately. */
    public const ORIGIN_ACADEMY = 'academy';

    /** @use HasFactory<SettingFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'key', 'value', 'scope_type', 'scope_id', 'origin', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
