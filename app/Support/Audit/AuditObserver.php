<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit entry for every create, update and delete on an auditable model.
 *
 * Deliberately records only the fields that actually changed, with their before and after values.
 * A full row snapshot on every save would make the log expensive to store and useless to read —
 * the question a person asks it is "what changed", not "what did the record look like".
 */
final class AuditObserver
{
    public function __construct(
        private readonly AuditContext $context,
        private readonly TenantContext $tenancy,
    ) {}

    public function created(Model $model): void
    {
        $this->write($model, 'created', null, $this->scrub($model, $model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->scrub($model, $model->getChanges());

        // A save that changed nothing meaningful — a touch, or only excluded fields — is not an
        // event anyone needs to read about.
        if ($changes === []) {
            return;
        }

        $before = [];
        foreach (array_keys($changes) as $key) {
            $before[$key] = $model->getOriginal($key);
        }

        $this->write($model, 'updated', $before, $changes);
    }

    public function deleted(Model $model): void
    {
        $action = method_exists($model, 'isForceDeleting') && $model->isForceDeleting()
            ? 'deleted'
            : 'archived';

        $this->write($model, $action, $this->scrub($model, $model->getAttributes()), null);
    }

    public function restored(Model $model): void
    {
        $this->write($model, 'restored', null, null);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function write(Model $model, string $action, ?array $before, ?array $after): void
    {
        $actor = $this->context->describe();

        // The entry belongs to the tenant that owns the record, not to whoever happens to be
        // bound — those differ during provisioning and control-plane work.
        $tenantId = $model->getAttribute('tenant_id') ?? $this->tenancy->id();

        $this->tenancy->withoutScoping(function () use ($model, $action, $before, $after, $actor, $tenantId): void {
            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'actor_type' => $actor['actor_type'],
                'actor_id' => $actor['actor_id'],
                'actor_name' => $actor['actor_name'],
                'module' => method_exists($model, 'auditModule') ? $model->auditModule() : class_basename($model),
                'action' => $action,
                'auditable_type' => $model->getMorphClass(),
                'auditable_id' => $model->getKey(),
                'target_label' => method_exists($model, 'auditLabel') ? $model->auditLabel() : null,
                'before' => $before,
                'after' => $after,
                'ip' => $actor['ip'],
                'occurred_at' => now(),
            ]);
        });
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function scrub(Model $model, array $attributes): array
    {
        $excluded = method_exists($model, 'auditExcluded') ? $model->auditExcluded() : [];

        foreach ($excluded as $key) {
            if (array_key_exists($key, $attributes)) {
                // Record that a secret changed without recording the secret.
                if (in_array($key, ['password', 'two_factor_secret', 'remember_token'], true)) {
                    $attributes[$key] = '[redacted]';

                    continue;
                }

                unset($attributes[$key]);
            }
        }

        return $attributes;
    }
}
