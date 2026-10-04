<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\RelationTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Mother, father, guardian, uncle, sponsor.
 *
 * A lookup rather than a fixed list, because family structures and the words for them differ by
 * culture, and getting a parent's relationship wrong on a report is a small insult that lands.
 */
final class RelationType extends Model
{
    /** @use HasFactory<RelationTypeFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'name', 'code', 'sort'];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}
