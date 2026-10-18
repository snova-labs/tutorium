<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How the academy behaves, and where each answer came from.
 *
 * The thing worth testing is the provenance rather than the saving: a value the preset chose and a
 * value somebody here chose are the same row at the same scope, and telling them apart is the
 * whole reason this screen exists.
 */
final class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Sunrise Academy',
            'owner_name' => 'Sunrise Owner',
            'owner_email' => 'owner@sunrise.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'blank',
        ])['tenant'];
    }

    #[Test]
    public function every_group_arrives_with_what_each_setting_means(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $response = $this->getJson('/api/v1/settings')->assertOk();

        $this->assertSame(
            ['people', 'attendance', 'grading', 'periods', 'reports', 'locale', 'security'],
            array_column((array) $response->json('data'), 'key'),
        );

        // The control a key gets, and its wording, are the API's to state.
        $grace = $this->find('attendance.late_grace_minutes');

        $this->assertSame('number', $grace['type']);
        $this->assertSame(0, $grace['min']);
        $this->assertSame(120, $grace['max']);
        $this->assertSame(10, $grace['value']);

        $this->assertArrayHasKey('points', $this->find('grading.default_scheme')['choices']);
    }

    #[Test]
    public function a_value_nobody_has_touched_reads_as_the_shipped_default(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        // Every preset writes the attendance and period settings, so this one — which none of
        // them mentions — is the honest example of a value nothing has ever decided.
        $setting = $this->find('people.duplicate_check_enabled');

        $this->assertSame('system', $setting['source']);
        $this->assertNull($setting['origin']);
        $this->assertFalse($setting['set_here']);
        $this->assertTrue($setting['value']);
    }

    #[Test]
    public function a_value_the_preset_chose_is_not_mistaken_for_one_somebody_here_chose(): void
    {
        // A preset writes at tenant scope, exactly like an academy's own choice. Before the origin
        // column there was nothing to tell them apart.
        // The preset already wrote this key when the account was provisioned; this is that row.
        $this->inTenant(function (): void {
            Setting::query()->updateOrCreate(
                ['key' => 'attendance.low_threshold_pct', 'scope_type' => 'tenant', 'scope_id' => null],
                ['value' => 80, 'origin' => Setting::ORIGIN_PRESET],
            );
            app(SettingsResolver::class)->flush();
        });

        Sanctum::actingAs($this->owner(), ['staff']);
        $setting = $this->find('attendance.low_threshold_pct');

        $this->assertSame(80, $setting['value']);
        $this->assertSame('tenant', $setting['source']);
        $this->assertSame('preset', $setting['origin']);
        $this->assertFalse($setting['set_here']);
        // What it would be without the preset, so a screen can offer to go back.
        $this->assertSame(75, $setting['shipped_default']);
    }

    #[Test]
    public function saving_a_value_marks_it_as_this_academys_own(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $this->putJson('/api/v1/settings/attendance.late_grace_minutes', ['value' => 15])
            ->assertOk()
            ->assertJsonPath('data.value', 15)
            ->assertJsonPath('data.origin', 'academy')
            ->assertJsonPath('data.set_here', true);

        $this->assertSame(15, $this->inTenant(fn () => app(SettingsResolver::class)->get('attendance.late_grace_minutes')));
    }

    #[Test]
    public function reverting_goes_back_to_what_the_academy_was_given(): void
    {
        $this->inTenant(function (): void {
            Setting::query()->updateOrCreate(
                ['key' => 'periods.type', 'scope_type' => 'tenant', 'scope_id' => null],
                ['value' => 'termly', 'origin' => Setting::ORIGIN_PRESET],
            );
            app(SettingsResolver::class)->flush();
        });

        Sanctum::actingAs($this->owner(), ['staff']);

        // Changed here, then reverted: the preset's row was replaced, so the revert lands on the
        // shipped default rather than on "termly". Worth knowing, and worth saying on the screen.
        $this->putJson('/api/v1/settings/periods.type', ['value' => 'quarterly'])->assertOk();
        $this->deleteJson('/api/v1/settings/periods.type')
            ->assertOk()
            ->assertJsonPath('data.value', 'monthly')
            ->assertJsonPath('data.source', 'system')
            ->assertJsonPath('data.set_here', false);
    }

    #[Test]
    public function a_row_written_before_the_origin_was_recorded_is_still_the_academys_to_revert(): void
    {
        // Rows that predate the origin column have no origin. A value IS stored at tenant level,
        // so calling it a shipped default would be untrue, and reverting it must still work.
        $this->inTenant(function (): void {
            Setting::query()->updateOrCreate(
                ['key' => 'reports.include_notes', 'scope_type' => 'tenant', 'scope_id' => null],
                ['value' => false, 'origin' => null],
            );
            app(SettingsResolver::class)->flush();
        });

        Sanctum::actingAs($this->owner(), ['staff']);
        $setting = $this->find('reports.include_notes');

        $this->assertFalse($setting['value']);
        $this->assertNull($setting['origin']);
        $this->assertTrue($setting['set_here']);

        $this->deleteJson('/api/v1/settings/reports.include_notes')
            ->assertOk()
            ->assertJsonPath('data.value', true)
            ->assertJsonPath('data.source', 'system');
    }

    #[Test]
    public function the_owner_role_can_be_asked_for_a_sign_in_code(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        // The owner role is configured on its own, outside the roles catalogue, so a list built
        // from the catalogue alone refused the one role every academy has.
        $response = $this->getJson('/api/v1/settings')->assertOk();
        $this->assertContains('Owner', $response->json('meta.roles'));

        $this->putJson('/api/v1/settings/security.sign_in_code_roles', ['value' => ['Owner', 'Teacher']])
            ->assertOk()
            ->assertJsonPath('data.value', ['Owner', 'Teacher']);
    }

    #[Test]
    public function a_value_outside_what_the_setting_allows_is_refused(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        $this->putJson('/api/v1/settings/attendance.late_grace_minutes', ['value' => 500])->assertStatus(422);
        $this->putJson('/api/v1/settings/attendance.compulsory', ['value' => 'yes please'])->assertStatus(422);
        $this->putJson('/api/v1/settings/grading.default_scheme', ['value' => 'vibes'])->assertStatus(422);
        $this->putJson('/api/v1/settings/security.sign_in_code_roles', ['value' => ['Wizard']])->assertStatus(422);
    }

    #[Test]
    public function a_key_the_screen_does_not_offer_is_a_404(): void
    {
        Sanctum::actingAs($this->owner(), ['staff']);

        // A weekend belongs to a location, where it is actually decided.
        $this->putJson('/api/v1/settings/locale.weekend_days', ['value' => ['friday']])->assertNotFound();
        $this->putJson('/api/v1/settings/made.up.key', ['value' => 1])->assertNotFound();
    }

    #[Test]
    public function changing_how_the_academy_behaves_is_not_everybodys_job(): void
    {
        Sanctum::actingAs($this->person('Teacher'), ['staff']);

        $this->getJson('/api/v1/settings')->assertForbidden();
        $this->putJson('/api/v1/settings/attendance.compulsory', ['value' => false])->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function find(string $key): array
    {
        $groups = (array) $this->getJson('/api/v1/settings')->assertOk()->json('data');

        foreach ($groups as $group) {
            foreach ($group['settings'] as $setting) {
                if ($setting['key'] === $key) {
                    return $setting;
                }
            }
        }

        $this->fail("[{$key}] was not offered.");
    }

    private function owner(): User
    {
        return $this->person('Owner');
    }

    private function person(string $role): User
    {
        app(RolesAndPermissionsSeeder::class)->run($this->tenant);

        return $this->inTenant(function () use ($role): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles([$role]);

            return $user->fresh();
        });
    }

    /**
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    private function inTenant(Closure $callback): mixed
    {
        return app(TenantContext::class)->runAs($this->tenant, $callback);
    }
}
