<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Operator;
use App\Support\Security\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Two-factor for operators: enrolment, verification and recovery codes.
 *
 * Not optional. An operator account reaches every customer's data, so the console refuses an
 * operator who has not confirmed a second factor (EnsureOperator), and sign-in asks for a code
 * every time.
 */
final class OperatorTwoFactor
{
    public const RECOVERY_CODE_COUNT = 10;

    public function __construct(private readonly Totp $totp) {}

    /**
     * Start (or restart) enrolment with a fresh secret. Nothing is enforced until it is confirmed,
     * so an abandoned enrolment leaves the operator exactly where they were: unable to sign in.
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function beginEnrolment(Operator $operator): array
    {
        if ($operator->hasTwoFactor()) {
            throw ValidationException::withMessages([
                'two_factor' => 'Two-factor is already set up for this account. Ask another operator to reset it.',
            ]);
        }

        $secret = $this->totp->generateSecret();

        $operator->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step' => null,
        ])->save();

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->totp->provisioningUri($secret, $operator->email, $this->issuer()),
        ];
    }

    /**
     * Confirm enrolment with a code from the authenticator, which proves the secret arrived.
     *
     * @return array<int, string> the recovery codes, shown once and never again
     */
    public function confirmEnrolment(Operator $operator, string $code): array
    {
        if ($operator->two_factor_secret === null || $operator->hasTwoFactor()) {
            throw ValidationException::withMessages([
                'two_factor' => 'There is no enrolment waiting to be confirmed.',
            ]);
        }

        $step = $this->totp->verify($operator->two_factor_secret, $code);

        if ($step === null) {
            throw ValidationException::withMessages([
                'code' => 'That code does not match. Check the time on your phone and try the next one.',
            ]);
        }

        return DB::transaction(function () use ($operator, $step): array {
            $codes = $this->newRecoveryCodes();

            $operator->forceFill([
                'two_factor_confirmed_at' => now(),
                'two_factor_last_step' => $step,
                'two_factor_recovery_codes' => array_map($this->hash(...), $codes),
            ])->save();

            return $codes;
        });
    }

    /**
     * Check a code from the authenticator, or failing that a recovery code.
     *
     * @return 'totp'|'recovery'|null which one matched, or null if neither did
     */
    public function verify(Operator $operator, string $code): ?string
    {
        if (! $operator->hasTwoFactor() || $operator->two_factor_secret === null) {
            return null;
        }

        $step = $this->totp->verify($operator->two_factor_secret, $code, $operator->two_factor_last_step);

        if ($step !== null) {
            $operator->forceFill(['two_factor_last_step' => $step])->save();

            return 'totp';
        }

        return $this->consumeRecoveryCode($operator, $code) ? 'recovery' : null;
    }

    /**
     * Replace every recovery code. The old ones stop working at once.
     *
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(Operator $operator): array
    {
        $codes = $this->newRecoveryCodes();

        $operator->forceFill(['two_factor_recovery_codes' => array_map($this->hash(...), $codes)])->save();

        return $codes;
    }

    public function recoveryCodesRemaining(Operator $operator): int
    {
        return count($operator->two_factor_recovery_codes ?? []);
    }

    /** Single use: a matching code is removed as it is accepted. */
    private function consumeRecoveryCode(Operator $operator, string $code): bool
    {
        $candidate = $this->hash($code);
        $stored = $operator->two_factor_recovery_codes ?? [];

        foreach ($stored as $index => $hash) {
            if (is_string($hash) && hash_equals($hash, $candidate)) {
                unset($stored[$index]);
                $operator->forceFill(['two_factor_recovery_codes' => array_values($stored)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function newRecoveryCodes(): array
    {
        return array_map(
            static fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    /**
     * Recovery codes are long and random, so a plain hash is enough to make a leaked table
     * useless; normalising first means a code typed in capitals or with spaces still matches.
     */
    private function hash(string $code): string
    {
        return hash('sha256', Str::lower(preg_replace('/\s+/', '', $code) ?? ''));
    }

    private function issuer(): string
    {
        return config()->string('platform.name', 'Platform').' operator console';
    }
}
