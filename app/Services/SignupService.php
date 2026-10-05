<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PendingSignup;
use App\Models\SignupAttempt;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ConfirmSignupNotification;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creating an account without anyone at our end being involved.
 *
 * Two steps. Submitting the form only stores it and emails a link; nothing is provisioned until
 * the link is used (SL-402), so an address nobody owns never gets an account, a trial clock or a
 * place in anyone's metrics. The link is single-use and expires.
 *
 * What this deliberately does not do is keep a list of banned email providers: those lists reject
 * real teachers at small academies far more often than they stop anybody determined. Throttling by
 * address, network and domain does that job instead (SL-401).
 */
final class SignupService
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Accept the form and send the confirmation link. Nothing is provisioned here.
     *
     * @param array<string, mixed> $input
     */
    public function signUp(array $input, ?string $ip = null): PendingSignup
    {
        $email = $this->normalise((string) $input['owner_email']);

        $this->guardRate($email, $ip);
        $this->guardExistingAccount($email, $ip);

        $token = Str::random(48);

        $pending = DB::transaction(function () use ($input, $email, $ip, $token): PendingSignup {
            // One open signup per address. Submitting again replaces the earlier answers, and the
            // earlier link stops working.
            PendingSignup::query()->where('email', $email)->delete();

            return PendingSignup::query()->create([
                'email' => $email,
                // Plaintext to the recipient, hash to the database.
                'token_hash' => hash('sha256', $token),
                'payload' => [
                    'name' => $input['name'],
                    'owner_name' => $input['owner_name'],
                    // Hashed now, so the plain password is never stored, not even encrypted.
                    'password_hash' => Hash::make((string) $input['password']),
                    'country' => strtoupper((string) $input['country']),
                    'timezone' => $input['timezone'],
                    'preset_code' => $input['preset_code'],
                    'locale' => $input['locale'] ?? null,
                    'week_start' => $input['week_start'] ?? null,
                    'weekend_days' => $input['weekend_days'] ?? null,
                ],
                'ip' => $ip,
                'expires_at' => now()->addHours($this->confirmationHours()),
            ]);
        });

        $this->record($email, $ip, SignupAttempt::PENDING);

        Notification::route('mail', $email)->notify(new ConfirmSignupNotification(
            token: $token,
            academy: (string) $input['name'],
            hours: $this->confirmationHours(),
        ));

        return $pending;
    }

    /**
     * What a confirmation link is for, without using it. The confirmation page shows this, and
     * asks for a click, so a mail scanner that opens the link does not create the account.
     *
     * @return array{academy: string, email: string, expires_at: string}
     */
    public function describe(string $token): array
    {
        $pending = $this->findUsable($token);

        return [
            'academy' => (string) $pending->payload['name'],
            'email' => $pending->email,
            'expires_at' => $pending->expires_at->toIso8601String(),
        ];
    }

    /**
     * Use a confirmation link: provision the account and start the trial.
     *
     * @return array{tenant: Tenant, owner: User}
     */
    public function confirm(string $token): array
    {
        return DB::transaction(function () use ($token): array {
            // Locked, so two clicks on the same link cannot provision twice.
            $pending = $this->findUsable($token, lock: true);
            $payload = $pending->payload;

            $this->guardExistingAccount($pending->email, $pending->ip);

            $result = $this->provisioner->provision([
                'name' => $payload['name'],
                'owner_name' => $payload['owner_name'],
                'owner_email' => $pending->email,
                'password_hash' => $payload['password_hash'],
                // Using the emailed link is the proof.
                'email_verified_at' => now(),
                'country' => $payload['country'],
                'timezone' => $payload['timezone'],
                'preset_code' => $payload['preset_code'],
                'locale' => $payload['locale'] ?? null,
                'week_start' => $payload['week_start'] ?? null,
                'weekend_days' => $payload['weekend_days'] ?? null,
                'status' => Tenant::STATUS_TRIAL,
                // The trial starts now, not when the form was submitted.
                'trial_days' => (int) config('signup.trial_days', 14),
            ]);

            // Single use: once confirmed, the answers (and the password hash) are gone.
            $pending->delete();

            $this->record($pending->email, $pending->ip, SignupAttempt::CREATED);

            return ['tenant' => $result['tenant'], 'owner' => $result['owner']];
        });
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

    /** Remove links that can no longer be used. */
    public function purgeExpired(): int
    {
        return PendingSignup::query()->where('expires_at', '<', now()->subDay())->delete();
    }

    private function findUsable(string $token, bool $lock = false): PendingSignup
    {
        $query = PendingSignup::query()->where('token_hash', hash('sha256', $token));

        if ($lock) {
            $query->lockForUpdate();
        }

        $pending = $query->first();

        if ($pending === null) {
            throw ValidationException::withMessages([
                'token' => 'That link is not valid. It may already have been used: try signing in.',
            ]);
        }

        if ($pending->hasExpired()) {
            throw ValidationException::withMessages([
                'token' => 'That link has expired. Sign up again and we will send you a new one.',
            ]);
        }

        return $pending;
    }

    private function guardRate(string $email, ?string $ip): void
    {
        $limits = config('signup.limits');

        $byEmail = $this->recentAttempts()->where('email', $email)->count();

        if ($byEmail >= (int) $limits['per_email']) {
            $this->record($email, $ip, SignupAttempt::RATE_LIMITED, 'email');

            throw ValidationException::withMessages([
                'owner_email' => 'Too many attempts with this address. Try again in an hour.',
            ])->status(429);
        }

        if ($ip !== null) {
            $byIp = $this->recentAttempts()->where('ip', $ip)->count();

            if ($byIp >= (int) $limits['per_ip']) {
                $this->record($email, $ip, SignupAttempt::RATE_LIMITED, 'ip');

                throw ValidationException::withMessages([
                    'owner_email' => 'Too many accounts have been created from here recently. Try again later.',
                ])->status(429);
            }
        }

        $domain = $this->limitedDomain($email);

        if ($domain !== null) {
            $byDomain = $this->recentAttempts()->where('domain', $domain)->count();

            if ($byDomain >= (int) $limits['per_domain']) {
                $this->record($email, $ip, SignupAttempt::RATE_LIMITED, 'domain');

                throw ValidationException::withMessages([
                    'owner_email' => 'Too many accounts have been requested for addresses at '.$domain
                        .' recently. Try again later, or ask a colleague who already has an account '
                        .'to invite you.',
                ])->status(429);
            }
        }
    }

    /**
     * Submissions in the last hour. A confirmation is the second half of a submission already
     * counted, so it does not count again.
     *
     * @return Builder<SignupAttempt>
     */
    private function recentAttempts(): Builder
    {
        return SignupAttempt::query()
            ->where('outcome', '!=', SignupAttempt::CREATED)
            ->where('attempted_at', '>=', now()->subHour());
    }

    private function guardExistingAccount(string $email, ?string $ip): void
    {
        $exists = $this->tenancy->withoutScoping(
            fn () => User::query()->where('email', $email)->exists(),
        );

        if (! $exists) {
            return;
        }

        $this->record($email, $ip, SignupAttempt::REJECTED, 'already registered');

        // The same address may legitimately staff more than one academy, so this is not a
        // duplicate-account error: it is a nudge toward the door they probably wanted.
        throw ValidationException::withMessages([
            'owner_email' => 'That address already has an account. Sign in, or ask the owner of the '
                .'academy you want to join to invite you.',
        ]);
    }

    /** The domain counted for throttling, or null for a shared mailbox provider. */
    private function limitedDomain(string $email): ?string
    {
        $domain = Str::after($email, '@');

        if ($domain === '' || $domain === $email) {
            return null;
        }

        return in_array($domain, config('signup.shared_mail_domains', []), true) ? null : $domain;
    }

    private function record(string $email, ?string $ip, string $outcome, ?string $reason = null): void
    {
        SignupAttempt::query()->create([
            'email' => $email,
            'domain' => $this->limitedDomain($email),
            'ip' => $ip,
            'outcome' => $outcome,
            'reason' => $reason,
            'attempted_at' => now(),
        ]);
    }

    private function normalise(string $email): string
    {
        return strtolower(trim($email));
    }

    private function confirmationHours(): int
    {
        return max(1, (int) config('signup.confirmation_hours', 48));
    }
}
