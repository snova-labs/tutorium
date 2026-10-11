<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BrandService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BrandLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['branding.logo.disk' => 'local']);

        // An upload is multipart rather than JSON, so the header is what the client sends to get
        // a refusal it can read beside the field rather than a redirect.
        $this->withHeader('Accept', 'application/json');
    }

    #[Test]
    public function an_owner_can_upload_replace_and_remove_a_logo(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('logo-one');
        $brand = app(TenantContext::class)->runAs($tenant, fn () => Brand::factory()->create());
        Sanctum::actingAs($owner);

        $this->post("/api/v1/brands/{$brand->getKey()}/logo", ['logo' => UploadedFile::fake()->image('mark.png', 400, 200)])
            ->assertOk()
            ->assertJsonPath('data.logo_url', route('api.brands.logo.show', $brand->getKey()));

        $first = $brand->refresh()->logo_path;
        $this->assertNotNull($first);
        Storage::disk('local')->assertExists($first);

        // The endpoint serves the file itself: the disk is private, so there is no public URL.
        $this->get("/api/v1/brands/{$brand->getKey()}/logo")->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, private');

        $this->post("/api/v1/brands/{$brand->getKey()}/logo", ['logo' => UploadedFile::fake()->image('new.png', 300, 300)])
            ->assertOk();

        $second = $brand->refresh()->logo_path;
        $this->assertNotSame($first, $second);
        // Replacing a logo does not leave the old file behind to be served or billed for.
        Storage::disk('local')->assertMissing($first);

        $this->delete("/api/v1/brands/{$brand->getKey()}/logo")->assertOk()
            ->assertJsonPath('data.logo_url', null);

        Storage::disk('local')->assertMissing($second);
        $this->get("/api/v1/brands/{$brand->getKey()}/logo")->assertNotFound();
    }

    #[Test]
    public function a_document_is_not_accepted_as_a_logo(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('logo-two');
        $brand = app(TenantContext::class)->runAs($tenant, fn () => Brand::factory()->create());
        Sanctum::actingAs($owner);

        // An SVG is a document that can carry script, and this file is served back to browsers.
        $this->post("/api/v1/brands/{$brand->getKey()}/logo", [
            'logo' => UploadedFile::fake()->createWithContent('mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ])->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->post("/api/v1/brands/{$brand->getKey()}/logo", [
            'logo' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->assertNull($brand->refresh()->logo_path);
    }

    #[Test]
    public function an_oversized_image_is_refused(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('logo-three');
        $brand = app(TenantContext::class)->runAs($tenant, fn () => Brand::factory()->create());
        Sanctum::actingAs($owner);

        $this->post("/api/v1/brands/{$brand->getKey()}/logo", [
            'logo' => UploadedFile::fake()->image('huge.png', 120, 120)->size((int) config('branding.logo.max_kb') + 50),
        ])->assertStatus(422)->assertJsonValidationErrors('logo');
    }

    #[Test]
    public function another_academys_logo_is_out_of_reach(): void
    {
        [, $ownerA] = $this->tenantWithOwner('logo-four');
        [$tenantB] = $this->tenantWithOwner('logo-five');

        $brandB = app(TenantContext::class)->runAs($tenantB, function (): Brand {
            $brand = Brand::factory()->create();
            app(BrandService::class)->setLogo($brand, UploadedFile::fake()->image('theirs.png', 200, 100));

            return $brand->refresh();
        });

        Sanctum::actingAs($ownerA);

        // Not-found rather than forbidden: a 403 would confirm the brand exists somewhere.
        $this->get("/api/v1/brands/{$brandB->getKey()}/logo")->assertNotFound();
        $this->post("/api/v1/brands/{$brandB->getKey()}/logo", ['logo' => UploadedFile::fake()->image('mine.png')])
            ->assertNotFound();
        $this->delete("/api/v1/brands/{$brandB->getKey()}/logo")->assertNotFound();

        Storage::disk('local')->assertExists($brandB->logo_path);
    }

    #[Test]
    public function a_teacher_may_see_the_logo_but_not_change_it(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('logo-six');
        $brand = app(TenantContext::class)->runAs($tenant, fn () => Brand::factory()->create());

        Sanctum::actingAs($owner);
        $this->post("/api/v1/brands/{$brand->getKey()}/logo", ['logo' => UploadedFile::fake()->image('mark.png')])->assertOk();

        $teacher = app(TenantContext::class)->runAs($tenant, function (): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles(['Teacher']);

            return $user->fresh();
        });

        Sanctum::actingAs($teacher);

        $this->get("/api/v1/brands/{$brand->getKey()}/logo")->assertOk();
        $this->delete("/api/v1/brands/{$brand->getKey()}/logo")->assertForbidden();

        $this->assertNotNull($brand->refresh()->logo_path);
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
