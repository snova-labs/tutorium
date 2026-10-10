<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Operator;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Moving an account between states.
 *
 * The principle running through all of it: **restricting service is not the same as withholding
 * data**. A suspended account keeps reading and exporting everything; only writing stops. Holding
 * records about children hostage over an expired card would be indefensible, and would generate
 * exactly the chargebacks and complaints it deserves (SL-BIL-006 §6).
 */
final class TenantLifecycleService
{
    /** How long a cancelled account is kept before it is purged for good. */
    private const RETENTION_DAYS = 30;

    public function __construct(private readonly TenantContext $tenancy) {}

    public function suspend(Tenant $tenant, Operator $operator, string $reason): Tenant
    {
        return $this->transition($tenant, $operator, Tenant::STATUS_SUSPENDED, $reason, [
            'suspended_at' => now(),
        ]);
    }

    public function reactivate(Tenant $tenant, Operator $operator, string $reason = 'Payment received'): Tenant
    {
        // Reactivating restores everything instantly. Nothing was taken away, so nothing has to be
        // rebuilt — which is the whole reason suspension is read-only rather than destructive.
        return $this->transition($tenant, $operator, Tenant::STATUS_ACTIVE, $reason, [
            'suspended_at' => null,
            'purge_after' => null,
        ]);
    }

    public function cancel(Tenant $tenant, Operator $operator, string $reason): Tenant
    {
        return $this->transition($tenant, $operator, Tenant::STATUS_CANCELLED, $reason, [
            'purge_after' => now()->addDays(self::RETENTION_DAYS),
        ]);
    }

    /**
     * Irreversible deletion.
     *
     * Four guards, none of them decorative: the account must be cancelled, its retention window
     * must have passed, an export must already exist, and the caller must type the account's slug.
     * Everything about this operation should feel like it is trying to talk you out of it.
     */
    /**
     * Remove every row the tenant owns before the tenant itself.
     *
     * The organisation tables restrict deleting their tenant, and other tables restrict deleting
     * those (a batch holds on to its branch), so no single delete order cascades cleanly. The rows
     * go with foreign-key checks off, then the tenant row goes with them on, so the audit log's
     * nullOnDelete still fires. Audit rows are never deleted: production revokes that right.
     */
    private function deleteTenantRows(Tenant $tenant): void
    {
        $tenantId = $tenant->getKey();
        $teamColumn = app(PermissionRegistrar::class)->teamsKey;

        Schema::withoutForeignKeyConstraints(function () use ($tenantId, $teamColumn): void {
            $userIds = DB::table('users')->where('tenant_id', $tenantId)->pluck('id');

            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $userIds)
                ->delete();

            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                if (in_array($table, ['tenants', 'audit_logs'], true)) {
                    continue;
                }

                $columns = Schema::getColumnListing($table);

                foreach (['tenant_id', $teamColumn] as $column) {
                    if (in_array($column, $columns, true)) {
                        DB::table($table)->where($column, $tenantId)->delete();
                    }
                }
            }
        });
    }

    /**
     * Remove the demonstration academy outright, so it can be built again.
     *
     * Bypasses the export and retention rules that protect a customer, which is why it only
     * accepts the one tenant the demo builder creates, and never on production.
     */
    public function removeDemo(Tenant $tenant): void
    {
        if ($tenant->slug !== DemoAcademyBuilder::SLUG || app()->isProduction()) {
            throw new RuntimeException('Only the demo academy can be removed this way, and never on production.');
        }

        DB::transaction(function () use ($tenant): void {
            $this->tenancy->withoutScoping(function () use ($tenant): void {
                $this->deleteTenantRows($tenant);
                AuditLog::query()->where('tenant_id', $tenant->getKey())->delete();
                $tenant->forceDelete();
            });
        });
    }

    public function purge(Tenant $tenant, Operator $operator, string $confirmation, bool $exportExists): Tenant
    {
        if ($tenant->status !== Tenant::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'tenant' => 'Only a cancelled account can be purged. Cancel it first.',
            ]);
        }

        if ($tenant->purge_after !== null && $tenant->purge_after->isFuture()) {
            throw ValidationException::withMessages([
                'tenant' => 'The retention window has not passed. This account can be purged after '
                    .$tenant->purge_after->toDateString().'.',
            ]);
        }

        if (! $exportExists) {
            throw ValidationException::withMessages([
                'tenant' => 'Produce an export first. Deleting a customer\'s records without offering '
                    .'them a copy is not something this system will do.',
            ]);
        }

        if ($confirmation !== $tenant->slug) {
            throw ValidationException::withMessages([
                'confirmation' => 'Type the account identifier exactly to confirm: '.$tenant->slug,
            ]);
        }

        return DB::transaction(function () use ($tenant, $operator): Tenant {
            // The audit entry is written before the data goes, and deliberately carries no tenant
            // id, so it survives the cascade that removes everything else.
            $this->tenancy->withoutScoping(function () use ($tenant, $operator): void {
                AuditLog::query()->create([
                    'tenant_id' => null,
                    'actor_type' => AuditLog::ACTOR_OPERATOR,
                    'actor_id' => $operator->getKey(),
                    'actor_name' => $operator->name.' (support)',
                    'module' => 'Platform',
                    'action' => 'tenant_purged',
                    'target_label' => $tenant->name.' ('.$tenant->slug.')',
                    'before' => ['slug' => $tenant->slug, 'name' => $tenant->name],
                    'occurred_at' => now(),
                ]);

                $tenant->update(['status' => Tenant::STATUS_PURGED]);
                $this->deleteTenantRows($tenant);
                $tenant->forceDelete();
            });

            return $tenant;
        });
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function transition(Tenant $tenant, Operator $operator, string $status, string $reason, array $attributes = []): Tenant
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Say why. Someone will read this in six months and need to understand it.',
            ]);
        }

        $from = $tenant->status;

        return DB::transaction(function () use ($tenant, $operator, $status, $reason, $attributes, $from): Tenant {
            $this->tenancy->withoutScoping(fn () => $tenant->fill(['status' => $status] + $attributes)->save());

            // Written into the customer's own log, not only ours. An account that goes read-only
            // should be able to see who did it and why without asking.
            $this->tenancy->withoutScoping(function () use ($tenant, $operator, $status, $reason, $from): void {
                AuditLog::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'actor_type' => AuditLog::ACTOR_OPERATOR,
                    'actor_id' => $operator->getKey(),
                    'actor_name' => $operator->name.' (support)',
                    'module' => 'Account',
                    'action' => 'status_changed',
                    'target_label' => $tenant->name,
                    'before' => ['status' => $from],
                    'after' => ['status' => $status, 'reason' => $reason],
                    'occurred_at' => now(),
                ]);
            });

            return $tenant->refresh();
        });
    }
}
