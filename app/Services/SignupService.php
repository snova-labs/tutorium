<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SignupAttempt;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating an account without anyone at our end being involved.
 *
 * Two things this deliberately does not do. It does not block the product until an address is
 * verified — a customer can build a timetable and take a register straight away, and only
 * outbound email to families is held back. And it does not maintain a list of banned email
 * providers, because those lists reject real teachers at small academies far more often than they
 * stop anybody determined.
 */
final class SignupService
{
    /** Attempts allowed from one address, and from one network, per hour. */
    private const PER_EMAIL_PER_HOUR = 3;

    private const PER_IP_PER_HOUR = 10;

    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{tenant: Tenant, owner: User}
     */
    public function signUp(array $input, ?string $ip = null): array
    {
        $email = strtolower(trim($input['owner_email']));

        $this->guardRate($email, $ip);
        $this->guardExistingAccount($email, $ip);

        $result = DB::transaction(function () use ($input, $email): array {
            $provisioned = $this->provisioner->provision(array_merge($input, [
                'owner_email' => $email,
                'status' => Tenant::STATUS_TRIAL,
                'trial_days' => (int) config('signup.trial_days', 14),
            ]));

            $this->tenancy->runAs($provisioned['tenant'], function () use ($provisioned): void {
                $this->issueVerification($provisioned['owner']);
            });

            return $provisioned;
        });

        $this->record($email, $ip, SignupAttempt::CREATED);

        return ['tenant' => $result['tenant'], 'owner' => $result['owner']->refresh()];
    }

    /** Sends, or re-sends, the verification email. */
    public function issueVerification(User $user): void
    {
        // Plaintext to the recipient, hash to the database. A leaked table should not contain
        // working links into other people's accounts.
        $token = Str::random(48);

        $user->forceFill([
            'verification_token_hash' => hash('sha256', $token),
            'verification_sent_at' => now(),
        ])->saveQuietly();

        $user->notify(new VerifyEmailNotification($token));
    }

    public function verify(string $token): User
    {
        $hash = hash('sha256', $token);

        $user = $this->tenancy->withoutScoping(
            fn () => User::query()->where('verification_token_hash', $hash)->first()
        );

        if ($user === null) {
            throw ValidationException::withMessages([
                'token' => 'That link is not valid. It may already have been used — try signing in.',
            ]);
        }

        $sentAt = $user->verification_sent_at;

        if ($sentAt !== null && CarbonImmutable::parse($sentAt)->addHours(48)->isPast()) {
            throw ValidationException::withMessages([
                'token' => 'That link has expired. Sign in and we will send you another.',
            ]);
        }

        // Single use: the token is cleared, so a link forwarded to someone else does nothing.
        $user->forceFill([
            'email_verified_at' => now(),
            'verification_token_hash' => null,
        ])->saveQuietly();

        return $user->refresh();
    }

    /**
     * Whether this account may send email to families yet.
     *
     * Called before any outbound delivery. Everything else in the product works regardless.
     */
    public function maySendOutbound(Tenant $tenant): bool
    {
        return $this->tenancy->runAs(
            $tenant,
            fn (): bool => User::query()->whereNotNull('email_verified_at')->exists(),
        );
    }

    private function guardRate(string $email, ?string $ip): void
    {
        $since = now()->subHour();

        $byEmail = SignupAttempt::query()
            ->where('email', $email)->where('attempted_at', '>=', $since)->count();

        if ($byEmail >= self::PER_EMAIL_PER_HOUR) {
            $this->record($email, $ip, SignupAttempt::RATE_LIMITED, 'email');

            throw ValidationException::withMessages([
                'owner_email' => 'Too many attempts with this address. Try again in an hour.',
            ])->status(429);
        }

        if ($ip !== null) {
            $byIp = SignupAttempt::query()
                ->where('ip', $ip)->where('attempted_at', '>=', $since)->count();

            if ($byIp >= self::PER_IP_PER_HOUR) {
                $this->record($email, $ip, SignupAttempt::RATE_LIMITED, 'ip');

                throw ValidationException::withMessages([
                    'owner_email' => 'Too many accounts have been created from here recently. Try again later.',
                ])->status(429);
            }
        }
    }

    private function guardExistingAccount(string $email, ?string $ip): void
    {
        $exists = $this->tenancy->withoutScoping(
            fn () => User::query()->where('email', $email)->exists()
        );

        if (! $exists) {
            return;
        }

        $this->record($email, $ip, SignupAttempt::REJECTED, 'already registered');

        // The same address may legitimately staff more than one academy, so this is not a
        // duplicate-account error — it is a nudge toward the door they probably wanted.
        throw ValidationException::withMessages([
            'owner_email' => 'That address already has an account. Sign in, or ask the owner of the '
                .'academy you want to join to invite you.',
        ]);
    }

    private function record(string $email, ?string $ip, string $outcome, ?string $reason = null): void
    {
        SignupAttempt::query()->create([
            'email' => $email,
            'ip' => $ip,
            'outcome' => $outcome,
            'reason' => $reason,
            'attempted_at' => now(),
        ]);
    }
}
