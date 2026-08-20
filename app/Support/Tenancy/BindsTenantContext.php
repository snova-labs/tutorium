<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;

/**
 * Job middleware that binds the tenant for the duration of a queued job and releases it after,
 * so a worker process cannot carry one tenant's context into the next job.
 */
final class BindsTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! method_exists($job, 'resolveTenant')) {
            return $next($job);
        }

        $context = app(TenantContext::class);

        return $context->runAs($job->resolveTenant(), function () use ($next, $job) {
            try {
                return $next($job);
            } finally {
                $context = app(TenantContext::class);
                $context->forget();
            }
        });
    }
}
