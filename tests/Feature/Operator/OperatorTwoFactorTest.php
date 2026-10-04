<?php

declare(strict_types=1);

namespace Tests\Feature\Operator;

use App\Models\Operator;
use App\Services\OperatorTwoFactor;
use App\Support\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An operator account reaches every customer's data, so the console is closed to anyone who has
 * not proved a second factor, at enrolment and at every sign-in.
 */
final class OperatorTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-enough-password';

    #[Test]
    public function an_operator_without_two_factor_gets_only_an_enrolment_token(): void
    {
        $this->operator(enrolled: false);

        $response = $this->login()->assertOk()->assertJsonPath('data.two_factor', 'enrolment_required');
        $token = $response->json('data.token');

        // The password alone opens nothing but the enrolment screen.
        $this->withToken($token)->getJson('/operator/v1/tenants')->assertForbidden();
    }

    #[Test]
    public function enrolment_confirmed_with_a_real_code_issues_recovery_codes_and_a_console_token(): void
    {
        $operator = $this->operator(enrolled: false);
        $enrolToken = $this->login()->json('data.token');

        $enrolment = $this->withToken($enrolToken)->postJson('/operator/v1/auth/two-factor/enrol')->assertOk();
        $secret = $enrolment->json('data.secret');
        $this->assertStringStartsWith('otpauth://totp/', $enrolment->json('data.otpauth_uri'));

        $confirmed = $this->withToken($enrolToken)
            ->postJson('/operator/v1/auth/two-factor/confirm', ['code' => $this->codeFor($secret)])
            ->assertOk();

        $this->assertCount(10, $confirmed->json('data.recovery_codes'));
        $this->assertTrue($operator->refresh()->hasTwoFactor());

        $this->resetAuth();
        $this->withToken($confirmed->json('data.token'))->getJson('/operator/v1/tenants')->assertOk();

        // The enrolment token is spent; it does not linger as a second way in.
        $this->resetAuth();
        $this->withToken($enrolToken)->postJson('/operator/v1/auth/two-factor/enrol')->assertUnauthorized();
    }

    #[Test]
    public function a_wrong_code_does_not_confirm_enrolment(): void
    {
        $operator = $this->operator(enrolled: false);
        $enrolToken = $this->login()->json('data.token');
        $this->withToken($enrolToken)->postJson('/operator/v1/auth/two-factor/enrol')->assertOk();

        $this->withToken($enrolToken)
            ->postJson('/operator/v1/auth/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertFalse($operator->refresh()->hasTwoFactor());
    }

    #[Test]
    public function sign_in_requires_a_valid_code_and_never_says_which_part_was_wrong(): void
    {
        $operator = $this->operator();

        $this->login()->assertStatus(422)->assertJsonValidationErrors('code');
        $this->login('000000')->assertStatus(422)->assertJsonValidationErrors('email');

        $wrongPassword = $this->postJson('/operator/v1/auth/login', [
            'email' => $operator->email, 'password' => 'not-the-password', 'code' => $this->codeFor($operator),
        ])->assertStatus(422);
        $unknown = $this->postJson('/operator/v1/auth/login', [
            'email' => 'nobody@sample.test', 'password' => self::PASSWORD, 'code' => '123456',
        ])->assertStatus(422);

        $this->assertSame($wrongPassword->json('errors'), $unknown->json('errors'));

        $token = $this->login($this->codeFor($operator))->assertOk()->json('data.token');
        $this->withToken($token)->getJson('/operator/v1/tenants')->assertOk();
        $this->assertNotNull($operator->refresh()->last_login_at);
    }

    #[Test]
    public function a_code_cannot_be_used_twice(): void
    {
        $operator = $this->operator();
        $code = $this->codeFor($operator);

        $this->login($code)->assertOk();
        $this->login($code)->assertStatus(422);
    }

    #[Test]
    public function a_recovery_code_works_once(): void
    {
        $operator = $this->operator();
        $codes = $this->recoveryCodesFor($operator);

        $this->login(strtoupper($codes[0]))->assertOk()->assertJsonPath('data.recovery_codes_remaining', 9);
        $this->login($codes[0])->assertStatus(422);
    }

    #[Test]
    public function regenerating_recovery_codes_retires_the_old_ones_and_needs_a_current_code(): void
    {
        $operator = $this->operator();
        $old = $this->recoveryCodesFor($operator);
        $token = $this->login($old[0])->json('data.token');

        // A console token is not enough on its own, and neither is a recovery code.
        $this->withToken($token)
            ->postJson('/operator/v1/auth/two-factor/recovery-codes', ['code' => $old[1]])
            ->assertStatus(422);

        $fresh = $this->withToken($token)
            ->postJson('/operator/v1/auth/two-factor/recovery-codes', ['code' => $this->codeFor($operator)])
            ->assertOk()
            ->json('data.recovery_codes');

        $this->assertCount(10, $fresh);

        $this->resetAuth();
        $this->login($old[2])->assertStatus(422);
        $this->login($fresh[0])->assertOk();
    }

    #[Test]
    public function an_enrolled_operator_cannot_quietly_re_enrol(): void
    {
        $operator = $this->operator();
        $enrolToken = $operator->createToken('enrol', ['two-factor:enrol'])->plainTextToken;

        $this->withToken($enrolToken)->postJson('/operator/v1/auth/two-factor/enrol')->assertStatus(422);
    }

    #[Test]
    public function secrets_are_encrypted_at_rest_and_never_serialised(): void
    {
        $operator = $this->operator();
        $this->recoveryCodesFor($operator);

        $raw = DB::table('operators')->where('id', $operator->getKey())->first();

        $this->assertNotSame($operator->refresh()->two_factor_secret, $raw->two_factor_secret);
        $this->assertArrayNotHasKey('two_factor_secret', $operator->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $operator->toArray());
    }

    #[Test]
    public function signing_out_revokes_the_token(): void
    {
        $operator = $this->operator();
        $token = $this->login($this->codeFor($operator))->json('data.token');

        $this->withToken($token)->postJson('/operator/v1/auth/logout')->assertOk();

        $this->resetAuth();
        $this->withToken($token)->getJson('/operator/v1/tenants')->assertUnauthorized();
    }

    #[Test]
    public function a_disabled_operator_cannot_sign_in(): void
    {
        $operator = $this->operator();
        $operator->update(['is_active' => false]);

        $this->login($this->codeFor($operator))->assertStatus(422);
    }

    private function operator(bool $enrolled = true): Operator
    {
        $factory = Operator::factory()->state(['password' => Hash::make(self::PASSWORD)]);

        return ($enrolled ? $factory : $factory->withoutTwoFactor())->create();
    }

    /** @return TestResponse<JsonResponse> */
    private function login(?string $code = null): TestResponse
    {
        $this->resetAuth();

        return $this->postJson('/operator/v1/auth/login', array_filter([
            'email' => Operator::query()->value('email'),
            'password' => self::PASSWORD,
            'code' => $code,
        ]));
    }

    private function codeFor(Operator|string $secretOrOperator): string
    {
        $totp = app(Totp::class);
        $secret = $secretOrOperator instanceof Operator
            ? (string) $secretOrOperator->refresh()->two_factor_secret
            : $secretOrOperator;

        return $totp->codeAt($secret, $totp->currentStep());
    }

    /** @return array<int, string> */
    private function recoveryCodesFor(Operator $operator): array
    {
        return app(OperatorTwoFactor::class)->regenerateRecoveryCodes($operator);
    }

    /** Each request authenticates afresh, as separate HTTP requests would. */
    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
