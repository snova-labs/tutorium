<?php

declare(strict_types=1);

namespace Tests\Feature\Signup;

use App\Models\Branch;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use App\Services\InvitationService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Bringing colleagues in, without opening a door nobody meant to open.
 */
final class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $result = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
        ]);

        $this->tenant = $result['tenant'];
        $this->owner = $result['owner'];
    }

    #[Test]
    public function an_invitation_grants_nothing_until_it_is_accepted(): void
    {
        $invitation = $this->invite(['email' => 'teacher@example.test', 'role_name' => 'Teacher']);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            // No account exists, so nothing appears in a staff list or gets mistaken for a
            // colleague who has actually joined.
            $this->assertNull(User::query()->where('email', 'teacher@example.test')->first());
        });

        app(InvitationService::class)->accept($this->token($invitation), ['password' => 'a-long-enough-password']);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $user = User::query()->where('email', 'teacher@example.test')->first();

            $this->assertNotNull($user);
            $this->assertTrue($user->hasRole('Teacher'));
            // Accepting an invitation sent to an address is itself proof of the address.
            $this->assertNotNull($user->email_verified_at);
        });
    }

    #[Test]
    public function nobody_can_invite_into_a_role_stronger_than_their_own(): void
    {
        $frontDesk = $this->staff('Front desk', 'front@sample.test');

        try {
            app(InvitationService::class)->invite($frontDesk, [
                'email' => 'ringer@example.test',
                'role_name' => 'Management',
            ]);
            $this->fail('Escalation should be refused.');
        } catch (ValidationException $e) {
            // "Manage users" must not quietly mean "become the owner".
            $this->assertStringContainsString('permissions you do not have yourself', $e->getMessage());
        }
    }

    #[Test]
    public function only_the_owner_can_invite_another_owner(): void
    {
        $management = $this->staff('Management', 'manager@sample.test');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only the account owner');

        app(InvitationService::class)->invite($management, [
            'email' => 'second-owner@example.test',
            'role_name' => 'Owner',
        ]);
    }

    #[Test]
    public function someone_limited_to_one_location_cannot_grant_access_to_all(): void
    {
        $limited = $this->staff('Management', 'limited@sample.test', scopeAll: false);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('locations you can reach yourself');

        app(InvitationService::class)->invite($limited, [
            'email' => 'someone@example.test',
            'role_name' => 'Teacher',
            'scope_all_branches' => true,
        ]);
    }

    #[Test]
    public function branch_scope_carries_across_to_the_accepted_account(): void
    {
        $branchId = app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => Branch::query()->first()->getKey(),
        );

        $invitation = $this->invite([
            'email' => 'teacher@example.test',
            'role_name' => 'Teacher',
            'scope_all_branches' => false,
            'branch_ids' => [$branchId],
        ]);

        $user = app(InvitationService::class)
            ->accept($this->token($invitation), ['password' => 'a-long-enough-password']);

        app(TenantContext::class)->runAs($this->tenant, function () use ($user, $branchId): void {
            $fresh = $user->fresh();

            $this->assertFalse($fresh->scope_all_branches);
            $this->assertTrue($fresh->canAccessBranch($branchId));
        });
    }

    #[Test]
    public function an_expired_invitation_cannot_be_used(): void
    {
        $invitation = $this->invite(['email' => 'teacher@example.test', 'role_name' => 'Teacher']);
        $token = $this->token($invitation);

        $this->travel(8)->days();

        try {
            app(InvitationService::class)->accept($token, ['password' => 'a-long-enough-password']);
            $this->fail('An expired invitation should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
    }

    #[Test]
    public function a_revoked_invitations_link_stops_working(): void
    {
        $invitation = $this->invite(['email' => 'teacher@example.test', 'role_name' => 'Teacher']);
        $token = $this->token($invitation);

        app(InvitationService::class)->revoke($invitation);

        // A revoked invitation whose token still works is not revoked.
        $this->expectException(ValidationException::class);
        app(InvitationService::class)->accept($token, ['password' => 'a-long-enough-password']);
    }

    #[Test]
    public function accepting_twice_is_impossible(): void
    {
        $invitation = $this->invite(['email' => 'teacher@example.test', 'role_name' => 'Teacher']);
        $token = $this->token($invitation);

        app(InvitationService::class)->accept($token, ['password' => 'a-long-enough-password']);

        $this->expectException(ValidationException::class);
        app(InvitationService::class)->accept($token, ['password' => 'a-long-enough-password']);
    }

    #[Test]
    public function re_inviting_replaces_the_link_rather_than_stacking_them(): void
    {
        $first = $this->invite(['email' => 'teacher@example.test', 'role_name' => 'Teacher']);
        $firstToken = $this->token($first);

        Notification::fake();
        $second = $this->invite(['email' => 'teacher@example.test', 'role_name' => 'Teacher']);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertSame(1, Invitation::query()->count());
            $this->assertSame(2, Invitation::query()->first()->send_count);
        });

        // The old link no longer works.
        $this->expectException(ValidationException::class);
        app(InvitationService::class)->accept($firstToken, ['password' => 'a-long-enough-password']);
    }

    #[Test]
    public function the_stored_token_is_only_a_hash(): void
    {
        $invitation = $this->invite(['email' => 'teacher@example.test', 'role_name' => 'Teacher']);
        $token = $this->token($invitation);

        app(TenantContext::class)->runAs($this->tenant, function () use ($token): void {
            $stored = Invitation::query()->first()->getAttributes()['token_hash'];

            // A leaked table should not contain working invitations into other people's accounts.
            $this->assertNotSame($token, $stored);
            $this->assertSame(hash('sha256', $token), $stored);
        });
    }

    /** @param array<string, mixed> $input */
    private function invite(array $input): Invitation
    {
        return app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => app(InvitationService::class)->invite($this->owner->fresh(), $input),
        );
    }

    private function staff(string $role, string $email, bool $scopeAll = true): User
    {
        return app(TenantContext::class)->runAs($this->tenant, function () use ($role, $email, $scopeAll): User {
            $user = User::factory()->create([
                'email' => $email, 'is_active' => true, 'scope_all_branches' => $scopeAll,
            ]);
            $user->syncRoles([$role]);

            return $user->fresh();
        });
    }

    private function token(Invitation $invitation): string
    {
        $captured = null;

        Notification::assertSentOnDemand(
            StaffInvitationNotification::class,
            function ($notification) use (&$captured) {
                $captured = (new \ReflectionProperty($notification, 'token'))->getValue($notification);

                return true;
            },
        );

        return $captured;
    }
}
