<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What a tenant calls one of our concepts.
 *
 * A corporate training provider reading "participant" should see it in every screen, every email
 * and every report — not in some of them (FR-CFG-4).
 */
final class TerminologyOverride extends Model
{
    use Auditable, BelongsToTenant, HasFactory;

    protected $fillable = ['tenant_id', 'term_key', 'singular', 'plural', 'locale'];

    public function auditModule(): string
    {
        return 'Settings';
    }
}
