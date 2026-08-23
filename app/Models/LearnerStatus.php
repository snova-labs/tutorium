<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Prospect, Active, On hold, Completed, Withdrawn — editable, because the set differs per academy. */
final class LearnerStatus extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'name', 'code', 'is_terminal', 'color', 'sort'];

    protected function casts(): array
    {
        return ['is_terminal' => 'boolean', 'sort' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'Settings';
    }
}
