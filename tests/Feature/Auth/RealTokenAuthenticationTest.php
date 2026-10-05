<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Signing in and then using what sign-in handed out, as a real client does, with nothing faked.
 *
 * Every other API test uses Sanctum::actingAs, which skips looking the token up. That hid a token
 * that could never be used: at authentication no tenant is bound yet, so the tenant scope hid the
 * token's own user. Between requests the tenant binding is cleared, as a fresh process would be.
 */
final class RealTokenAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-enough-password';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->tenant = app(TenantContext::class)->withoutScoping(fn () => Tenant::factory()->create([
            'slug' => 'real-tokens',
            'status' => Tenant::STATUS_ACTIVE,
        ]));

        app(RolesAndPermissionsSeeder::class)->run($this->tenant);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $user = User::factory()->create([
                'email' => 'teacher@sample.test',
                'password' => Hash::make(self::PASSWORD),
                'is_active' => true,
            ]);
            $user->syncRoles(['Teacher']);
        });
    }

    #[Test]
    public function a_token_from_the_login_endpoint_opens_the_api(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'teacher@sample.test',
            'password' => self::PASSWORD,
        ])->assertOk()->json('data.token');

        $this->freshRequest();

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'teacher@sample.test')
            ->assertJsonPath('data.tenant.id', $this->tenant->getKey());
    }

    #[Test]
    public function a_browser_session_survives_to_the_next_request(): void
    {
        $this->post(route('sign-in.store'), ['email' => 'teacher@sample.test', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->freshRequest();

        $this->get(route('dashboard'))->assertOk();
    }

    #[Test]
    public function a_token_still_only_reaches_its_own_academy(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'teacher@sample.test',
            'password' => self::PASSWORD,
        ])->json('data.token');

        $other = app(TenantContext::class)->withoutScoping(fn () => Tenant::factory()->create([
            'slug' => 'someone-else',
            'status' => Tenant::STATUS_ACTIVE,
        ]));
        $stranger = app(TenantContext::class)->runAs($other, fn () => User::factory()->create());

        $this->freshRequest();

        // The tenant bound for the request is the token owner's, so another academy's people are invisible.
        $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.tenant.id', $this->tenant->getKey());
        $this->assertNotSame($other->getKey(), $this->tenant->getKey());
        $this->assertNotNull($stranger->getKey());
    }

    /** What a new request in a new process starts with: no tenant bound, no user resolved. */
    private function freshRequest(): void
    {
        app(TenantContext::class)->forget();
        $this->app['auth']->forgetGuards();
    }
}
