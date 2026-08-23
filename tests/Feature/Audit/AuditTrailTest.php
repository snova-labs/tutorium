<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::factory()->create(['slug' => 'audit-test']),
        );
    }

    #[Test]
    public function creating_a_record_writes_an_entry(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $brand = Brand::factory()->create(['name' => 'Sample Brand One']);

            $entry = AuditLog::query()->latest('id')->first();

            $this->assertNotNull($entry);
            $this->assertSame('created', $entry->action);
            $this->assertSame('Organisation', $entry->module);
            $this->assertSame($brand->getKey(), $entry->auditable_id);
            $this->assertSame('Sample Brand One', $entry->target_label);
            $this->assertSame($this->tenant->getKey(), $entry->tenant_id);
        });
    }

    #[Test]
    public function updating_records_only_what_changed(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $brand = Brand::factory()->create(['name' => 'Old name', 'locale' => 'en']);
            $brand->update(['name' => 'New name']);

            $entry = AuditLog::query()->where('action', 'updated')->latest('id')->first();

            $this->assertSame(['name' => 'Old name'], $entry->before);
            $this->assertSame(['name' => 'New name'], $entry->after);
            $this->assertArrayNotHasKey('locale', $entry->after, 'Unchanged fields must not be logged.');
        });
    }

    #[Test]
    public function a_save_that_changes_nothing_writes_nothing(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $brand = Brand::factory()->create();
            $before = AuditLog::query()->count();

            $brand->touch();

            $this->assertSame($before, AuditLog::query()->count());
        });
    }

    #[Test]
    public function secrets_are_recorded_as_changed_but_never_stored(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $user = User::factory()->create();
            $user->update(['password' => 'a-brand-new-secret']);

            $entry = AuditLog::query()
                ->where('module', 'People')->where('action', 'updated')
                ->latest('id')->first();

            $this->assertSame('[redacted]', $entry->after['password'] ?? null);
            $this->assertStringNotContainsString('a-brand-new-secret', json_encode($entry->after));
        });
    }

    #[Test]
    public function entries_cannot_be_updated(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            Brand::factory()->create();
            $entry = AuditLog::query()->latest('id')->first();

            $this->expectException(\LogicException::class);
            $entry->update(['action' => 'something-else']);
        });
    }

    #[Test]
    public function one_tenant_cannot_read_another_tenants_trail(): void
    {
        $other = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::factory()->create(['slug' => 'audit-other']),
        );

        app(TenantContext::class)->runAs($this->tenant, fn () => Brand::factory()->create());

        app(TenantContext::class)->runAs($other, function (): void {
            $this->assertSame(0, AuditLog::query()->count());
        });
    }
    #[Test]
    #[DataProvider('tenantResources')]
    public function moving_a_record_between_tenants_is_refused(string $model): void
    {
        [$tenantA, $tenantB] = $this->twoTenants();

        $record = $this->makeFor($tenantA, $model);

        $this->context()->runAs($tenantA, function () use ($record, $tenantB): void {
            try {
                $record->update(['tenant_id' => $tenantB->getKey()]);
            } catch (TenancyException|LogicException) {
                return;   // refused, which is the contract
            }

            $this->fail($record::class.' allowed its tenant_id to be reassigned.');
        });
    }
}