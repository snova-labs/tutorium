<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

final class TenancyException extends RuntimeException
{
    public static function missingContext(): self
    {
        return new self(
            'No tenant is bound to the current context. Requests must pass through ResolveTenant; '
            .'queued jobs must use the TenantAware trait; control-plane work must call '
            .'TenantContext::withoutScoping() explicitly.'
        );
    }

    public static function mismatch(string $model, int|string $expected, int|string $actual): self
    {
        return new self(sprintf(
            'Refusing to write %s for tenant %s while tenant %s is bound. This is a tenancy bug, '
            .'not a validation failure.',
            $model,
            $actual,
            $expected,
        ));
    }

    public static function unresolvableJob(string $job): self
    {
        return new self(sprintf(
            'Job %s carries no tenant id. A tenant-aware job must be dispatched from a bound '
            .'context so it can re-bind on execution.',
            $job,
        ));
    }
}
