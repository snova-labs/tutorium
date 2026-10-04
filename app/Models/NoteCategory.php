<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\NoteCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** General, Attendance, Participation, Academic, Behaviour — and whatever else a tenant adds. */
final class NoteCategory extends Model
{
    /** @use HasFactory<NoteCategoryFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'name', 'code', 'report_visible_default', 'color', 'sort'];

    protected function casts(): array
    {
        return ['report_visible_default' => 'boolean', 'sort' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}
