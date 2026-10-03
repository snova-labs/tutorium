<?php

declare(strict_types=1);

namespace Tests\Feature\Signup;

use App\Models\AttendanceStatus;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\SignupService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A stranger becoming a customer, with nobody at our end involved.
 */
final class SelfServeSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    #[Test]
    public function signing_up_produces_an_account_that_works_immediately(): void
    {
        $result = $this->signUp();

        $this->assertSame(Tenant::STATUS_TRIAL, $result['tenant']->status);
        $this->assertNotNull($result['tenant']->trial_ends_at);

        app(TenantContext::class)->runAs($result['tenant'], function () use ($result): void {
            // Roles, brand, branch, vocabularies — all of it, from one form submission.
            $this->assertTrue($result['owner']->fresh()->isOwner());
            $this->assertSame('Asia/Kathmandu', Branch::query()->first()->timezone);
            $this->assertGreaterThan(0, AttendanceStatus::query()->count());
        });
    }

    #[Test]
    public function the_product_works_before_the_address_is_verified(): void
    {
        $result = $this->signUp();

        $this->assertNull($result['owner']->email_verified_at);

        // Verification gates outbound email to families, not the product itself.
        $this->assertFalse(app(SignupService::class)->maySendOutbound($result['tenant']));

        app(TenantContext::class)->runAs($result['tenant'], function (): void {
            $this->assertTrue(Branch::query()->exists(), 'Setup is not blocked by verification.');
        });
    }

    #[Test]
    public function confirming_the_address_unlocks_sending(): void
    {
        $result = $this->signUp();

        $token = $this->capturedToken($result['owner']);
        app(SignupService::class)->verify($token);

        $this->assertTrue(app(SignupService::class)->maySendOutbound($result['tenant']));
        $this->assertNotNull($result['owner']->refresh()->email_verified_at);
    }

    #[Test]
    public function a_verification_link_works_only_once(): void
    {
        $result = $this->signUp();
        $token = $this->capturedToken($result['owner']);

        app(SignupService::class)->verify($token);

        // A link forwarded to someone else does nothing.
        $this->expectException(ValidationException::class);
        app(SignupService::class)->verify($token);
    }

    #[Test]
    public function an_expired_link_says_so_and_offers_a_way_forward(): void
    {
        $result = $this->signUp();
        $token = $this->capturedToken($result['owner']);

        $this->travel(49)->hours();

        try {
            app(SignupService::class)->verify($token);
            $this->fail('An expired link should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('we will send you another', $e->getMessage());
        }
    }

    #[Test]
    public function an_existing_address_is_pointed_at_the_right_door(): void
    {
        $this->signUp(['owner_email' => 'someone@example.test']);

        try {
            $this->signUp(['owner_email' => 'someone@example.test']);
            $this->fail('A duplicate address should be refused.');
        } catch (ValidationException $e) {
            // The same person may legitimately staff two academies, so this is a nudge rather
            // than a scolding.
            $this->assertStringContainsString('ask the owner', $e->getMessage());
        }
    }

    #[Test]
    public function repeated_attempts_from_one_address_are_throttled(): void
    {
        foreach (range(1, 3) as $i) {
            try {
                $this->signUp(['owner_email' => 'repeat@example.test']);
            } catch (ValidationException) {
                // The second and third are rejected as duplicates, which still counts as attempts.
            }
        }

        try {
            $this->signUp(['owner_email' => 'repeat@example.test']);
            $this->fail('A fourth attempt should be throttled.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Too many attempts', $e->getMessage());
        }
    }

    #[Test]
    public function attempts_from_one_network_are_throttled_separately(): void
    {
        foreach (range(1, 10) as $i) {
            $this->signUp(['owner_email' => "person{$i}@example.test"], '203.0.113.9');
        }

        try {
            $this->signUp(['owner_email' => 'person11@example.test'], '203.0.113.9');
            $this->fail('An eleventh account from one address should be throttled.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('from here recently', $e->getMessage());
        }

        // Someone else, elsewhere, is unaffected.
        $this->signUp(['owner_email' => 'elsewhere@example.test'], '198.51.100.4');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function two_academies_with_the_same_name_both_succeed(): void
    {
        $first = $this->signUp(['name' => 'Bright Futures', 'owner_email' => 'a@example.test']);
        $second = $this->signUp(['name' => 'Bright Futures', 'owner_email' => 'b@example.test']);

        $this->assertSame('bright-futures', $first['tenant']->slug);
        $this->assertSame('bright-futures-2', $second['tenant']->slug);
    }

    #[Test]
    public function the_public_endpoint_validates_before_creating_anything(): void
    {
        $this->postJson('/api/v1/signup', [
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@example.test',
            'password' => 'short',
            'timezone' => 'Asia/Katmandu',   // one 'h' short
            'preset_code' => 'kids-tutoring-south-asia',
        ])->assertStatus(422)->assertJsonValidationErrors(['password', 'timezone']);

        $this->assertSame(0, Tenant::query()->count());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{tenant: Tenant, owner: User}
     */
    private function signUp(array $overrides = [], string $ip = '203.0.113.1'): array
    {
        return app(SignupService::class)->signUp(array_merge([
            'name' => 'Sample Academy '.uniqid(),
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner-'.uniqid().'@example.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
        ], $overrides), $ip);
    }

    private function capturedToken(User $user): string
    {
        $captured = null;

        Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use (&$captured) {
            $reflection = new \ReflectionProperty($notification, 'token');
            $captured = $reflection->getValue($notification);

            return true;
        });

        return $captured;
    }
}
