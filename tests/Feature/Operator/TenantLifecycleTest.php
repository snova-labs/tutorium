<?php

declare(strict_types=1);

namespace Tests\Feature\Operator;

use App\Models\AuditLog;
use App\Models\Operator;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Services\TenantExportService;
use App\Services\TenantLifecycleService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Restricting service is not the same as withholding data.
 */
final class TenantLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Operator $operator;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = Operator::factory()->create();
        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'blank',
            'status' => Tenant::STATUS_ACTIVE,
        ])['tenant'];
    }

    #[Test]
    public function suspending_is_read_only_and_reversible(): void
    {
        $suspended = app(TenantLifecycleService::class)
            ->suspend($this->tenant, $this->operator, 'Payment failed after four retries');

        $this->assertTrue($suspended->isSuspended());
        $this->assertFalse($suspended->isOperational());

        // Nothing was taken away, so nothing has to be rebuilt.
        $reactivated = app(TenantLifecycleService::class)
            ->reactivate($this->tenant->refresh(), $this->operator, 'Payment received');

        $this->assertTrue($reactivated->isOperational());
        $this->assertNull($reactivated->suspended_at);
    }

    #[Test]
    public function a_status_change_appears_in_the_customers_own_log(): void
    {
        app(TenantLifecycleService::class)
            ->suspend($this->tenant, $this->operator, 'Payment failed after four retries');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $entry = AuditLog::query()->where('action', 'status_changed')->latest('id')->first();

            // An account that goes read-only should be able to see who did it and why.
            $this->assertSame('active', $entry->before['status']);
            $this->assertSame('suspended', $entry->after['status']);
            $this->assertStringContainsString('Payment failed', $entry->after['reason']);
        });
    }

    #[Test]
    public function a_status_change_without_a_reason_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(TenantLifecycleService::class)->suspend($this->tenant, $this->operator, '');
    }

    #[Test]
    public function cancelling_keeps_the_data_for_a_retention_window(): void
    {
        $cancelled = app(TenantLifecycleService::class)
            ->cancel($this->tenant, $this->operator, 'Closing the centre');

        $this->assertSame(Tenant::STATUS_CANCELLED, $cancelled->status);
        $this->assertTrue($cancelled->purge_after->isFuture());
        $this->assertSame(30, (int) now()->diffInDays($cancelled->purge_after));
    }

    #[Test]
    public function purging_refuses_every_shortcut(): void
    {
        $service = app(TenantLifecycleService::class);
        $operator = $this->operator;

        // Not cancelled yet.
        $this->assertRefused(fn () => $service->purge($this->tenant, $operator, $this->tenant->slug, true),
            'Only a cancelled account');

        $service->cancel($this->tenant, $operator, 'Closing the centre');

        // Retention window has not passed.
        $this->assertRefused(fn () => $service->purge($this->tenant->refresh(), $operator, $this->tenant->slug, true),
            'retention window');

        $this->travel(31)->days();

        // No export exists.
        $this->assertRefused(fn () => $service->purge($this->tenant->refresh(), $operator, $this->tenant->slug, false),
            'Produce an export first');

        // Confirmation does not match.
        $this->assertRefused(fn () => $service->purge($this->tenant->refresh(), $operator, 'wrong-slug', true),
            'Type the account identifier');
    }

    #[Test]
    public function a_purge_leaves_a_record_that_survives_the_deletion(): void
    {
        $service = app(TenantLifecycleService::class);
        $slug = $this->tenant->slug;

        $service->cancel($this->tenant, $this->operator, 'Closing the centre');
        $this->travel(31)->days();

        $service->purge($this->tenant->refresh(), $this->operator, $slug, true);

        $this->assertNull(Tenant::query()->where('slug', $slug)->first());

        // Deliberately carries no tenant id, so it survives the cascade that removed everything else.
        $entry = app(TenantContext::class)->withoutScoping(
            fn () => AuditLog::query()->where('action', 'tenant_purged')->latest('id')->first(),
        );

        $this->assertNotNull($entry);
        $this->assertNull($entry->tenant_id);
        $this->assertStringContainsString($slug, $entry->target_label);
    }

    #[Test]
    public function an_export_can_be_produced_for_a_suspended_account(): void
    {
        app(TenantLifecycleService::class)
            ->suspend($this->tenant, $this->operator, 'Payment failed after four retries');

        $export = app(TenantExportService::class)->request($this->tenant->refresh(), operator: $this->operator);

        // Their records are theirs, whatever they owe us.
        $this->assertSame(TenantExport::STATUS_QUEUED, $export->status);
        $this->assertSame($this->operator->getKey(), $export->requested_by_operator_id);
    }

    private function assertRefused(callable $action, string $expectedMessage): void
    {
        try {
            $action();
            $this->fail("Expected refusal containing [{$expectedMessage}].");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        }
    }
}
