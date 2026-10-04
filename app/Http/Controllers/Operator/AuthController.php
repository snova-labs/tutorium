<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operator;

use App\Models\Operator;
use App\Services\OperatorTwoFactor;
use App\Services\SignInCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Operator sign-in, with a second factor every time.
 *
 * The second factor is a code emailed at sign-in. An operator who has set up an authenticator app
 * uses that instead (or a recovery code), and is then never offered the weaker email route.
 * Only a token with the "console" ability opens the console.
 */
final class AuthController
{
    public const ABILITY_CONSOLE = 'console';

    private const CONSOLE_TOKEN_HOURS = 12;

    public function __construct(
        private readonly OperatorTwoFactor $twoFactor,
        private readonly SignInCodes $signInCodes,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);

        $operator = Operator::query()->where('email', $validated['email'])->first();

        // Checked against a throwaway hash when there is no such operator, so the time taken does
        // not reveal which addresses exist. One message for every failure, for the same reason.
        $passwordMatches = Hash::check($validated['password'], $operator->password ?? Hash::make(Str::random(32)));

        if ($operator === null || ! $passwordMatches || ! $operator->is_active) {
            throw $this->refused();
        }

        $code = $validated['code'] ?? null;

        if (! $operator->hasTwoFactor()) {
            if ($code === null) {
                $this->signInCodes->send($operator);

                throw ValidationException::withMessages([
                    'code' => 'We have emailed you a sign-in code. Enter it to finish signing in.',
                ]);
            }

            if (! $this->signInCodes->verify($operator, $code)) {
                throw $this->refused();
            }

            $method = 'email';
        } else {
            if ($code === null) {
                throw ValidationException::withMessages([
                    'code' => 'Enter the code from your authenticator app, or a recovery code.',
                ]);
            }

            $method = $this->twoFactor->verify($operator, $code);

            if ($method === null) {
                throw $this->refused();
            }
        }

        $operator->forceFill(['last_login_at' => now()])->save();

        return response()->json(['data' => array_filter([
            'token' => $this->token($operator, self::ABILITY_CONSOLE, now()->addHours(self::CONSOLE_TOKEN_HOURS)),
            // Said out loud when a recovery code was used, so running low is noticed in time.
            'recovery_codes_remaining' => $method === 'recovery'
                ? $this->twoFactor->recoveryCodesRemaining($operator)
                : null,
        ], fn ($value) => $value !== null)]);
    }

    public function enrol(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->twoFactor->beginEnrolment($this->operator($request))]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $operator = $this->operator($request);

        $codes = $this->twoFactor->confirmEnrolment($operator, $validated['code']);

        return response()->json(['data' => [
            'recovery_codes' => $codes,
            'message' => 'Store these somewhere safe. Each works once, and they will not be shown again. '
                .'From now on, sign in with your authenticator app instead of an emailed code.',
        ]]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $operator = $this->operator($request);

        // A stolen console token alone is not enough to mint new recovery codes.
        if ($this->twoFactor->verify($operator, $validated['code']) !== 'totp') {
            throw ValidationException::withMessages([
                'code' => 'Enter a current code from your authenticator app.',
            ]);
        }

        return response()->json(['data' => [
            'recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($operator),
            'message' => 'Your previous recovery codes no longer work.',
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->currentToken($request)->delete();

        return response()->json(['data' => ['message' => 'Signed out.']]);
    }

    private function token(Operator $operator, string $ability, \DateTimeInterface $expiresAt): string
    {
        return $operator->createToken('operator-'.$ability, [$ability], $expiresAt)->plainTextToken;
    }

    private function operator(Request $request): Operator
    {
        $operator = $request->user('operator');

        if (! $operator instanceof Operator) {
            abort(401);
        }

        return $operator;
    }

    /** The API is stateless, so the operator always arrives with a personal access token. */
    private function currentToken(Request $request): PersonalAccessToken
    {
        return $this->operator($request)->currentAccessToken();
    }

    private function refused(): ValidationException
    {
        return ValidationException::withMessages([
            'email' => 'Those details do not match an operator account.',
        ]);
    }
}
