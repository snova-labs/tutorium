<?php

declare (strict_types = 1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Support\Tenancy\TenancyException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Runs the same isolation contract against every registered tenant-owned model.
 *
 * This is the blocking check referenced by milestone M1 (SL-PLN-008 §5): "can a user of one
 * tenant reach another's data by any route?" must be answerable with a green suite, not with
 * an opinion.
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: class-string<Model>}> */
    public static function tenantResources(): array
    {
        $config = require __DIR__ . '/../../../config/tenancy.php';

        $cases = [];

        foreach ($config['resources'] as $model) {
            $cases[class_basename($model)] = [$model];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('tenantResources')]
    public function records_are_invisible_to_other_tenants(string $model): void
    {
        [$tenantA, $tenantB] = $this->twoTenants();

        $record = $this->makeFor($tenantA, $model);

        $this->context()->runAs($tenantB, function () use ($model, $record): void {
            $this->assertNull(
                $model::query()->find($record->getKey()),
                "{$model} leaked across a tenant boundary on find().",
            );

            $this->assertSame(
                0,
                $model::query()->whereKey($record->getKey())->count(),
                "{$model} leaked across a tenant boundary on a filtered query.",
            );

            $this->assertNotContains(
                $record->getKey(),
                $model::query()->pluck('id')->all(),
                "{$model} leaked across a tenant boundary on a list query.",
            );
        });
    }

    #[Test]
    #[DataProvider('tenantResources')]
    public function records_are_stamped_with_the_bound_tenant(string $model): void
    {
        [$tenantA] = $this->twoTenants();

        $record = $this->makeFor($tenantA, $model);

        $this->assertSame(
            $tenantA->getKey(),
            $record->getAttribute($record->getTenantColumn()),
            "{$model} was created without being stamped with the bound tenant.",
        );
    }

    #[Test]
    #[DataProvider('tenantResources')]
    public function creating_for_another_tenant_is_refused(string $model): void
    {
        [$tenantA, $tenantB] = $this->twoTenants();

        $this->expectException(TenancyException::class);

        $this->context()->runAs($tenantA, function () use ($model, $tenantB): void {
            $model::factory()->create(['tenant_id' => $tenantB->getKey()]);
        });
    }

    #[Test]
    #[DataProvider('tenantResources')]
    public function moving_a_record_between_tenants_is_refused(string $model): void
    {
        [$tenantA, $tenantB] = $this->twoTenants();

        $record = $this->makeFor($tenantA, $model);

        $this->expectException(TenancyException::class);

        $this->context()->runAs($tenantA, function () use ($record, $tenantB): void {
            $record->update(['tenant_id' => $tenantB->getKey()]);
        });
    }

    #[Test]
    #[DataProvider('tenantResources')]
    public function queries_return_nothing_when_no_tenant_is_bound(string $model): void
    {
        [$tenantA] = $this->twoTenants();

        $this->makeFor($tenantA, $model);

        $this->context()->forget();

        $this->assertSame(
            0,
            $model::query()->count(),
            "{$model} returned rows with no tenant bound. Unscoped queries must fail closed, "
            . 'never open.',
        );
    }

    /** @return array{0: Tenant, 1: Tenant} */
    private function twoTenants(): array
    {
        return $this->context()->withoutScoping(fn() => [
            Tenant::factory()->create(['name' => 'Sample Academy One', 'slug' => 'sample-one']),
            Tenant::factory()->create(['name' => 'Sample Academy Two', 'slug' => 'sample-two']),
        ]);
    }

    private function makeFor(Tenant $tenant, string $model): Model
    {
        return $this->context()->runAs($tenant, fn() => $model::factory()->create());
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}