<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\IdSequence;
use App\Models\Tenant;
use App\Support\Sequences\IdSequenceService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IdSequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_formats_identifiers_from_tenant_configuration(): void
    {
        $tenant = $this->tenant();

        app(TenantContext::class)->runAs($tenant, function () use ($tenant): void {
            IdSequence::factory()->create([
                'tenant_id' => $tenant->getKey(),
                'entity' => 'learner',
                'prefix' => 'ACME-KTM-STU',
                'separator' => '-',
                'pad_width' => 4,
                'next_number' => 7,
            ]);

            $this->assertSame('ACME-KTM-STU-0007', app(IdSequenceService::class)->next('learner'));
            $this->assertSame('ACME-KTM-STU-0008', app(IdSequenceService::class)->next('learner'));
        });
    }

    #[Test]
    public function it_never_issues_the_same_number_twice(): void
    {
        $tenant = $this->tenant();

        app(TenantContext::class)->runAs($tenant, function () use ($tenant): void {
            IdSequence::factory()->create([
                'tenant_id' => $tenant->getKey(),
                'entity' => 'learner',
                'prefix' => 'S',
                'separator' => '',
                'pad_width' => 3,
                'next_number' => 1,
            ]);

            $service = app(IdSequenceService::class);
            $issued = [];

            for ($i = 0; $i < 50; $i++) {
                $issued[] = $service->next('learner');
            }

            $this->assertCount(50, array_unique($issued));
            $this->assertSame('S001', $issued[0]);
            $this->assertSame('S050', $issued[49]);
        });
    }

    #[Test]
    public function a_tenant_can_continue_previous_numbering(): void
    {
        // The migration objection this removes: "our students are already S001–S005."
        $tenant = $this->tenant();

        app(TenantContext::class)->runAs($tenant, function () use ($tenant): void {
            IdSequence::factory()->create([
                'tenant_id' => $tenant->getKey(),
                'entity' => 'learner',
                'prefix' => 'S',
                'separator' => '',
                'pad_width' => 3,
                'next_number' => 6,
            ]);

            $this->assertSame('S006', app(IdSequenceService::class)->next('learner'));
        });
    }

    #[Test]
    public function peek_does_not_consume_a_number(): void
    {
        $tenant = $this->tenant();

        app(TenantContext::class)->runAs($tenant, function () use ($tenant): void {
            IdSequence::factory()->create([
                'tenant_id' => $tenant->getKey(),
                'entity' => 'report',
                'prefix' => 'RPT',
                'separator' => '-',
                'pad_width' => 5,
                'next_number' => 42,
            ]);

            $service = app(IdSequenceService::class);

            $this->assertSame('RPT-00042', $service->peek('report'));
            $this->assertSame('RPT-00042', $service->peek('report'));
            $this->assertSame('RPT-00042', $service->next('report'));
        });
    }

    #[Test]
    public function sequences_are_independent_per_tenant(): void
    {
        $one = $this->tenant('sample-one');
        $two = $this->tenant('sample-two');
        $context = app(TenantContext::class);

        foreach ([$one, $two] as $tenant) {
            $context->runAs($tenant, function () use ($tenant): void {
                IdSequence::factory()->create([
                    'tenant_id' => $tenant->getKey(),
                    'entity' => 'learner',
                    'prefix' => 'S',
                    'separator' => '',
                    'pad_width' => 3,
                    'next_number' => 1,
                ]);
            });
        }

        $context->runAs($one, fn () => app(IdSequenceService::class)->next('learner'));
        $context->runAs($one, fn () => app(IdSequenceService::class)->next('learner'));

        $this->assertSame(
            'S001',
            $context->runAs($two, fn () => app(IdSequenceService::class)->next('learner')),
            'One tenant consuming numbers must not advance another tenant\'s sequence.',
        );
    }

    private function tenant(string $slug = 'sample-academy'): Tenant
    {
        return app(TenantContext::class)->withoutScoping(
            fn () => Tenant::factory()->create(['slug' => $slug])
        );
    }
}
