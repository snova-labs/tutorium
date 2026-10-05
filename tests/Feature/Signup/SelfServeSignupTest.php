<?php

declare(strict_types=1);

namespace Tests\Feature\Signup;

use App\Models\AttendanceStatus;
use App\Models\Branch;
use App\Models\PendingSignup;
use App\Models\SignupAttempt;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ConfirmSignupNotification;
use App\Services\SignupService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A stranger becoming a customer, with nobody at our end involved (SL-401, SL-402).
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
    public function nothing_is_provisioned_until_the_address_is_confirmed(): void
    {
        $pending = $this->signUp(['owner_email' => 'owner@academy.test']);

        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, app(TenantContext::class)->withoutScoping(fn () => User::query()->count()));
        $this->assertSame('owner@academy.test', $pending->email);

        Notification::assertSentOnDemand(ConfirmSignupNotification::class, function ($n, $channels, AnonymousNotifiable $notifiable) {
            return $notifiable->routes['mail'] === 'owner@academy.test';
        });
    }

    #[Test]
    public function confirming_provisions_a_working_account_with_a_verified_owner(): void
    {
        $this->signUp(['owner_email' => 'owner@academy.test', 'country' => 'np']);

        $result = app(SignupService::class)->confirm($this->capturedToken('owner@academy.test'));
        $tenant = $result['tenant'];

        $this->assertSame(Tenant::STATUS_TRIAL, $tenant->status);
        $this->assertSame('NP', $tenant->country);
        // The trial starts when the account exists, not when the form was sent.
        $this->assertTrue($tenant->trial_ends_at->isSameDay(now()->addDays(14)));
        $this->assertNotNull($result['owner']->email_verified_at);
        $this->assertTrue(app(SignupService::class)->maySendOutbound($tenant));

        app(TenantContext::class)->runAs($tenant, function () use ($result): void {
            $owner = $result['owner']->fresh();
            $this->assertTrue($owner->isOwner());
            $this->assertTrue(Hash::check('a-long-enough-password', $owner->password));
            $this->assertSame('Asia/Kathmandu', Branch::query()->first()->timezone);
            $this->assertGreaterThan(0, AttendanceStatus::query()->count());
        });

        // The stored answers, password hash included, are gone.
        $this->assertSame(0, PendingSignup::query()->count());
    }

    #[Test]
    public function the_form_answers_are_stored_encrypted_and_without_the_plain_password(): void
    {
        $this->signUp(['owner_email' => 'owner@academy.test', 'name' => 'Bright Futures']);

        $raw = (string) DB::table('pending_signups')->value('payload');

        $this->assertStringNotContainsString('Bright Futures', $raw);
        $this->assertStringNotContainsString('a-long-enough-password', $raw);
        $this->assertStringNotContainsString('a-long-enough-password', json_encode(PendingSignup::query()->first()->payload));
    }

    #[Test]
    public function a_confirmation_link_works_only_once(): void
    {
        $this->signUp(['owner_email' => 'owner@academy.test']);
        $token = $this->capturedToken('owner@academy.test');

        app(SignupService::class)->confirm($token);

        try {
            app(SignupService::class)->confirm($token);
            $this->fail('A used link should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already have been used', $e->getMessage());
        }

        $this->assertSame(1, Tenant::query()->count());
    }

    #[Test]
    public function an_expired_link_creates_nothing_and_says_what_to_do(): void
    {
        $this->signUp(['owner_email' => 'owner@academy.test']);
        $token = $this->capturedToken('owner@academy.test');

        $this->travel(49)->hours();

        try {
            app(SignupService::class)->confirm($token);
            $this->fail('An expired link should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Sign up again', $e->getMessage());
        }

        $this->assertSame(0, Tenant::query()->count());
    }

    #[Test]
    public function looking_at_a_link_does_not_use_it(): void
    {
        $this->signUp(['owner_email' => 'owner@academy.test', 'name' => 'Bright Futures']);
        $token = $this->capturedToken('owner@academy.test');

        // A mail scanner opening the link only reaches this.
        $this->getJson("/api/v1/signup/confirm/{$token}")
            ->assertOk()
            ->assertJsonPath('data.academy', 'Bright Futures')
            ->assertJsonPath('data.email', 'owner@academy.test');

        $this->assertSame(0, Tenant::query()->count());

        $this->postJson("/api/v1/signup/confirm/{$token}")
            ->assertCreated()
            ->assertJsonPath('data.account', 'Bright Futures');

        $this->assertSame(1, Tenant::query()->count());
    }

    #[Test]
    public function signing_up_again_replaces_the_earlier_link(): void
    {
        $this->signUp(['owner_email' => 'owner@academy.test', 'name' => 'First try']);
        $first = $this->capturedToken('owner@academy.test');

        $this->signUp(['owner_email' => 'owner@academy.test', 'name' => 'Second try']);
        $second = $this->capturedToken('owner@academy.test', last: true);

        $this->assertNotSame($first, $second);
        $this->assertSame(1, PendingSignup::query()->count());

        $this->expectException(ValidationException::class);
        app(SignupService::class)->confirm($first);
    }

    #[Test]
    public function an_existing_address_is_pointed_at_the_right_door(): void
    {
        $this->signUp(['owner_email' => 'someone@academy.test']);
        app(SignupService::class)->confirm($this->capturedToken('someone@academy.test'));

        try {
            $this->signUp(['owner_email' => 'someone@academy.test']);
            $this->fail('A registered address should be refused.');
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
            $this->signUp(['owner_email' => 'repeat@academy.test']);
        }

        try {
            $this->signUp(['owner_email' => 'repeat@academy.test']);
            $this->fail('A fourth attempt should be throttled.');
        } catch (ValidationException $e) {
            $this->assertSame(429, $e->status);
            $this->assertStringContainsString('Too many attempts', $e->getMessage());
        }
    }

    #[Test]
    public function attempts_from_one_network_are_throttled_separately(): void
    {
        foreach (range(1, 10) as $i) {
            $this->signUp(['owner_email' => "person{$i}@example{$i}.test"], '203.0.113.9');
        }

        try {
            $this->signUp(['owner_email' => 'person11@example11.test'], '203.0.113.9');
            $this->fail('An eleventh signup from one network should be throttled.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('from here recently', $e->getMessage());
        }

        // Someone else, elsewhere, is unaffected.
        $this->signUp(['owner_email' => 'elsewhere@example.test'], '198.51.100.4');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function many_signups_for_one_domain_are_throttled_across_networks(): void
    {
        foreach (range(1, 5) as $i) {
            $this->signUp(['owner_email' => "user{$i}@throwaway.test"], "198.51.100.{$i}");
        }

        try {
            $this->signUp(['owner_email' => 'user6@throwaway.test'], '198.51.100.99');
            $this->fail('A sixth signup for one domain should be throttled.');
        } catch (ValidationException $e) {
            $this->assertSame(429, $e->status);
            $this->assertStringContainsString('throwaway.test', $e->getMessage());
        }

        $this->assertSame(1, SignupAttempt::query()->where('reason', 'domain')->count());
    }

    #[Test]
    public function shared_mailbox_providers_are_not_throttled_as_a_domain(): void
    {
        foreach (range(1, 7) as $i) {
            $this->signUp(['owner_email' => "teacher{$i}@gmail.com"], "198.51.100.{$i}");
        }

        $this->assertSame(7, PendingSignup::query()->count());
    }

    #[Test]
    public function a_confirmation_does_not_count_against_the_limits_twice(): void
    {
        foreach (range(1, 5) as $i) {
            $this->signUp(['owner_email' => "a{$i}@school{$i}.test"], '203.0.113.20');
            app(SignupService::class)->confirm($this->capturedToken("a{$i}@school{$i}.test"));
        }

        // Five submissions and five confirmations from one network: still under ten.
        $this->signUp(['owner_email' => 'a6@school6.test'], '203.0.113.20');
        $this->assertSame(6, SignupAttempt::query()->where('outcome', SignupAttempt::PENDING)->count());
    }

    #[Test]
    public function two_academies_with_the_same_name_both_succeed(): void
    {
        $this->signUp(['name' => 'Bright Futures', 'owner_email' => 'a@one.test']);
        $this->signUp(['name' => 'Bright Futures', 'owner_email' => 'b@two.test']);

        $first = app(SignupService::class)->confirm($this->capturedToken('a@one.test'));
        $second = app(SignupService::class)->confirm($this->capturedToken('b@two.test'));

        $this->assertSame('bright-futures', $first['tenant']->slug);
        $this->assertSame('bright-futures-2', $second['tenant']->slug);
    }

    #[Test]
    public function the_public_endpoint_validates_before_storing_anything(): void
    {
        $this->postJson('/api/v1/signup', [
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@example.test',
            'password' => 'short',
            'country' => 'XX',
            'timezone' => 'Asia/Katmandu',   // one 'h' short
            'preset_code' => 'kids-tutoring-south-asia',
        ])->assertStatus(422)->assertJsonValidationErrors(['password', 'timezone', 'country']);

        $this->assertSame(0, PendingSignup::query()->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_public_endpoint_accepts_the_form_and_creates_nothing_yet(): void
    {
        $this->postJson('/api/v1/signup', $this->form(['owner_email' => 'owner@gmail.com']))
            ->assertStatus(202)
            ->assertJsonPath('data.email', 'owner@gmail.com');

        $this->assertSame(1, PendingSignup::query()->count());
        $this->assertSame(0, Tenant::query()->count());
    }

    #[Test]
    public function signup_can_be_closed_without_a_deploy(): void
    {
        config(['signup.open' => false]);

        $this->postJson('/api/v1/signup', $this->form(['owner_email' => 'owner@gmail.com']))
            ->assertStatus(503);

        $this->getJson('/api/v1/signup/options')->assertOk()->assertJsonPath('data.open', false);
        $this->assertSame(0, PendingSignup::query()->count());
    }

    #[Test]
    public function the_form_lists_verticals_presets_and_countries_with_their_timezones(): void
    {
        $data = $this->getJson('/api/v1/signup/options')->assertOk()->json('data');

        $this->assertContains('language', array_column($data['verticals'], 'code'));
        $this->assertNotContains('blank', array_column($data['presets'], 'code'));

        $countries = array_column($data['countries'], null, 'code');
        $nepal = $countries['NP'];
        $this->assertSame('Nepal', $nepal['name']);
        $this->assertSame(['Asia/Kathmandu'], $nepal['timezones']);
    }

    #[Test]
    public function expired_links_are_purged_with_the_answers_they_held(): void
    {
        $this->signUp(['owner_email' => 'old@academy.test']);
        $this->travel(4)->days();
        $this->signUp(['owner_email' => 'new@academy.test']);

        $this->assertSame(1, app(SignupService::class)->purgeExpired());
        $this->assertSame(['new@academy.test'], PendingSignup::query()->pluck('email')->all());
    }

    /** @param array<string, mixed> $overrides */
    private function signUp(array $overrides = [], string $ip = '203.0.113.1'): PendingSignup
    {
        return app(SignupService::class)->signUp($this->form($overrides), $ip);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sample Academy '.uniqid(),
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner-'.uniqid().'@example.test',
            'password' => 'a-long-enough-password',
            'country' => 'NP',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
        ], $overrides);
    }

    private function capturedToken(string $email, bool $last = false): string
    {
        $tokens = [];

        Notification::assertSentOnDemand(ConfirmSignupNotification::class, function ($notification, $channels, AnonymousNotifiable $notifiable) use ($email, &$tokens) {
            if ($notifiable->routes['mail'] === $email) {
                $tokens[] = (new \ReflectionProperty($notification, 'token'))->getValue($notification);
            }

            return true;
        });

        $this->assertNotEmpty($tokens, "No confirmation was sent to {$email}.");

        return $last ? end($tokens) : $tokens[0];
    }
}
