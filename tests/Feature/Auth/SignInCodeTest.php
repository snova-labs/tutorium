<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\SignInCode;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\SignInCodeNotification;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Academy staff in the roles that can see money or change who has access confirm every sign-in
 * with a code emailed to them, on the API and in the browser alike.
 */
final class SignInCodeTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-enough-password';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutVite();

        $this->tenant = app(TenantContext::class)->withoutScoping(fn () => Tenant::factory()->create([
            'slug' => 'codes',
            'status' => Tenant::STATUS_ACTIVE,
        ]));

        app(RolesAndPermissionsSeeder::class)->run($this->tenant);
    }

    #[Test]
    public function an_owner_signing_in_through_the_api_needs_the_emailed_code(): void
    {
        $owner = $this->user('Owner');

        $this->apiLogin()->assertStatus(422)->assertJsonValidationErrors('code')->assertJsonMissingPath('data.token');

        $this->apiLogin('not-it')->assertStatus(422)->assertJsonValidationErrors('code');

        $token = $this->apiLogin($this->emailedCode($owner))->assertOk()->json('data.token');
        $this->assertIsString($token);

        // Used once; the same code does not sign in a second device.
        $this->apiLogin($this->emailedCode($owner))->assertStatus(422);
    }

    #[Test]
    public function signing_in_with_an_emailed_code_confirms_the_address(): void
    {
        $owner = $this->user('Owner');
        $owner->forceFill(['email_verified_at' => null])->saveQuietly();

        $this->apiLogin()->assertStatus(422);
        $this->assertNull($owner->fresh()?->email_verified_at, 'Asking for a code proves nothing yet.');

        $this->apiLogin($this->emailedCode($owner))->assertOk();
        $this->assertNotNull($owner->fresh()?->email_verified_at);
    }

    #[Test]
    public function the_code_is_never_stored_as_typed(): void
    {
        $owner = $this->user('Owner');
        $this->apiLogin();

        $code = $this->emailedCode($owner);
        $row = DB::table('sign_in_codes')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString($code, json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('code_hash', SignInCode::query()->firstOrFail()->toArray());
    }

    #[Test]
    public function the_system_default_for_a_dotted_key_is_found(): void
    {
        // Settings keys contain dots; reading defaults through config()'s dot notation missed them.
        $roles = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(SettingsResolver::class)->get('security.sign_in_code_roles'),
        );

        $this->assertSame(['Owner', 'Management', 'Accountant'], $roles);
    }

    #[Test]
    public function roles_outside_the_academys_list_sign_in_with_a_password(): void
    {
        $teacher = $this->user('Teacher');

        $this->apiLogin()->assertOk()->assertJsonPath('data.user.id', $teacher->getKey());
        Notification::assertNothingSent();
    }

    #[Test]
    public function an_academy_can_choose_which_roles_need_a_code(): void
    {
        $teacher = $this->user('Teacher');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            app(SettingsResolver::class)->set('security.sign_in_code_roles', ['Owner', 'Teacher']);
        });

        $this->apiLogin()->assertStatus(422)->assertJsonValidationErrors('code');
        Notification::assertSentTo($teacher, SignInCodeNotification::class);
    }

    #[Test]
    public function the_browser_asks_for_the_code_before_signing_in(): void
    {
        $owner = $this->user('Owner');

        $this->post(route('sign-in.store'), ['email' => $owner->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('sign-in.code'));
        $this->assertGuest();

        $this->get(route('sign-in.code'))->assertOk()->assertSee($owner->email);

        $this->post(route('sign-in.code.verify'), ['code' => 'nope'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('sign-in.code.verify'), ['code' => $this->emailedCode($owner)])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($owner);
    }

    #[Test]
    public function the_code_screen_is_closed_without_a_fresh_password_step(): void
    {
        $owner = $this->user('Owner');

        $this->get(route('sign-in.code'))->assertRedirect(route('sign-in'));
        $this->post(route('sign-in.code.verify'), ['code' => '123456'])->assertRedirect(route('sign-in'));

        $this->post(route('sign-in.store'), ['email' => $owner->email, 'password' => self::PASSWORD]);
        $code = $this->emailedCode($owner);
        $this->travel(11)->minutes();

        $this->post(route('sign-in.code.verify'), ['code' => $code])->assertRedirect(route('sign-in'));
        $this->assertGuest();
    }

    #[Test]
    public function a_teacher_in_the_browser_goes_straight_in(): void
    {
        $teacher = $this->user('Teacher');

        $this->post(route('sign-in.store'), ['email' => $teacher->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($teacher);
    }

    private function user(string $role): User
    {
        return app(TenantContext::class)->runAs($this->tenant, function () use ($role): User {
            $user = User::factory()->create([
                'is_active' => true,
                'scope_all_branches' => true,
                'password' => Hash::make(self::PASSWORD),
            ]);
            $user->syncRoles([$role]);

            return $user->fresh() ?? $user;
        });
    }

    /** @return TestResponse<JsonResponse> */
    private function apiLogin(?string $code = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/login', array_filter([
            'email' => User::query()->withoutGlobalScopes()->value('email'),
            'password' => self::PASSWORD,
            'code' => $code,
        ]));
    }

    private function emailedCode(User $user): string
    {
        $code = null;

        Notification::assertSentTo($user, SignInCodeNotification::class, function (SignInCodeNotification $n) use (&$code): bool {
            $code = $n->code;

            return true;
        });

        return (string) $code;
    }
}
