<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation rules that only accept the current academy's records.
 *
 * A plain `exists` rule checks the table, not the tenant: an id from another academy passes it.
 * Where the id is then stored as it came (a course's brand, a slot's session type, a learner's
 * status), that would join records across academies. Use these for every tenant-owned table.
 */
final class TenantRule
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        // No tenant bound means nothing can match, rather than everything.
        return Rule::exists($table, $column)->where('tenant_id', app(TenantContext::class)->id() ?? 0);
    }
}
