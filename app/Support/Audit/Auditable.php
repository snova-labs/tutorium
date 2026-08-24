<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Support\Str;

/**
 * Marks a model whose writes are recorded in the activity log.
 *
 * Auditing is attached at the persistence layer rather than in services, so bulk operations,
 * imports and console commands are covered by the same mechanism as a form submission — there is
 * no write path that quietly avoids it (SL-ARC-002 §1, FR-AUD-1).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::observe(AuditObserver::class);
    }

    /** Grouping shown in the activity log filter. Override where the class name reads oddly. */
    public function auditModule(): string
    {
        return Str::headline(class_basename(static::class));
    }

    /** How this record is named to a human reading the log a year later. */
    public function auditLabel(): ?string
    {
        foreach (['name', 'title', 'code', 'number'] as $attribute) {
            if (! empty($this->getAttribute($attribute))) {
                return (string) $this->getAttribute($attribute);
            }
        }

        return null;
    }

    /**
     * Attributes never written to the log, by name.
     *
     * The fact that a password changed is recorded; the value is not. Same for tokens and
     * two-factor secrets (SL-SEC-004 §7.2).
     *
     * @return array<int, string>
     */
    public function auditExcluded(): array
    {
        return [
            'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
            'updated_at', 'created_at',
        ];
    }
}
