<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BrandApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_owner_can_create_and_list_brands(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('api-one');
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/brands', [
            'name' => 'Sample Brand One',
            'code' => 'ONE',
        ])->assertCreated()->assertJsonPath('data.code', 'ONE')
            ->assertJsonPath('data.is_default', true);

        $this->getJson('/api/v1/brands')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function front_desk_is_refused(): void
    {
        [$tenant] = $this->tenantWithOwner('api-two');

        $frontDesk = app(TenantContext::class)->runAs($tenant, function (): User {
            $user = User::factory()->create(['is_active' => true]);
            $user->syncRoles(['Front desk']);

            return $user->fresh();
        });

        Sanctum::actingAs($frontDesk);

        $this->postJson('/api/v1/brands', ['name' => 'Nope', 'code' => 'NOPE'])
            ->assertForbidden();
    }

    #[Test]
    public function another_tenants_brand_is_not_found_rather_than_forbidden(): void
    {
        [, $ownerA] = $this->tenantWithOwner('api-three');
        [$tenantB] = $this->tenantWithOwner('api-four');

        $brandB = app(TenantContext::class)->runAs($tenantB, fn () => Brand::factory()->create());

        Sanctum::actingAs($ownerA);

        // Not-found, never forbidden: a 403 would confirm the record exists somewhere.
        $this->getJson("/api/v1/brands/{$brandB->getKey()}")->assertNotFound();
        $this->patchJson("/api/v1/brands/{$brandB->getKey()}", ['name' => 'Hijacked'])->assertNotFound();
    }

    #[Test]
    public function codes_are_unique_per_tenant_not_globally(): void
    {
        [, $ownerA] = $this->tenantWithOwner('api-five');
        [, $ownerB] = $this->tenantWithOwner('api-six');

        Sanctum::actingAs($ownerA);
        $this->postJson('/api/v1/brands', ['name' => 'Main', 'code' => 'MAIN'])->assertCreated();

        // The same code in another academy must be accepted — and must not hint that it is taken.
        Sanctum::actingAs($ownerB);
        $this->postJson('/api/v1/brands', ['name' => 'Main', 'code' => 'MAIN'])->assertCreated();

        Sanctum::actingAs($ownerA);
        $this->postJson('/api/v1/brands', ['name' => 'Duplicate', 'code' => 'MAIN'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    #[Test]
    public function an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/brands')->assertUnauthorized();
    }

    #[Test]
    public function a_suspended_tenant_can_read_but_not_write(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('api-seven');

        app(TenantContext::class)->withoutScoping(
            fn () => $tenant->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save(),
        );

        Sanctum::actingAs($owner->fresh());

        $this->getJson('/api/v1/brands')->assertOk();
        $this->postJson('/api/v1/brands', ['name' => 'Blocked', 'code' => 'BLK'])->assertStatus(423);
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithOwner(string $slug): array
    {
        $context = app(TenantContext::class);

        $tenant = $context->withoutScoping(fn () => Tenant::factory()->create([
            'slug' => $slug,
            'status' => Tenant::STATUS_ACTIVE,
        ]));

        app(RolesAndPermissionsSeeder::class)->run($tenant);

        $owner = $context->runAs($tenant, function (): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles(['Owner']);

            return $user->fresh();
        });

        return [$tenant, $owner];
    }
}
