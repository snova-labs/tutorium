<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SignInCode;
use App\Models\User;
use App\Notifications\SignInCodeNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * Email as the second factor: a six-digit code, sent at sign-in, that proves the person signing
 * in can also read the account's inbox.
 *
 * Each code is valid for a few minutes, works once, is stored only as a keyed hash, and dies after
 * a handful of wrong guesses, so the million possibilities cannot be walked. Asking again within
 * the cool-down does not send another, so the endpoint cannot be used to flood an inbox.
 */
final class SignInCodes
{
    public const MINUTES_VALID = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_AFTER_SECONDS = 60;

    /** Caps guesses at MAX_ATTEMPTS × this per hour, however often sign-in is restarted. */
    public const MAX_PER_HOUR = 5;

    /**
     * Email a fresh code, retiring any earlier one.
     *
     * @return bool false when a code went out moments ago and still stands, or the hourly cap is hit
     */
    public function send(Model $subject): bool
    {
        $recent = $this->codes($subject)->live()
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('created_at', '>', now()->subSeconds(self::RESEND_AFTER_SECONDS))
            ->exists();

        if ($recent || $this->codes($subject)->where('created_at', '>', now()->subHour())->count() >= self::MAX_PER_HOUR) {
            return false;
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($subject, $code): void {
            $this->codes($subject)->live()->update(['consumed_at' => now()]);

            $this->codes($subject)->create([
                'code_hash' => $this->hash($code),
                'expires_at' => now()->addMinutes(self::MINUTES_VALID),
            ]);
        });

        /** @phpstan-ignore method.notFound (callers pass Notifiable models: operators and users) */
        $subject->notify(new SignInCodeNotification($code, self::MINUTES_VALID));

        return true;
    }

    /** Accept the latest live code once. A wrong guess counts against it. */
    public function verify(Model $subject, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        return DB::transaction(function () use ($subject, $code): bool {
            $current = $this->codes($subject)->live()->latest('id')->lockForUpdate()->first();

            if ($current === null || $current->attempts >= self::MAX_ATTEMPTS) {
                return false;
            }

            if (! preg_match('/^\d{6}$/', $code) || ! hash_equals((string) $current->getAttribute('code_hash'), $this->hash($code))) {
                $current->increment('attempts');

                return false;
            }

            $current->forceFill(['consumed_at' => now()])->save();

            // Using a code sent to the address proves the address, exactly as a confirmation link
            // would: academies created without self-serve signup confirm theirs this way.
            if ($subject instanceof User && $subject->email_verified_at === null) {
                $subject->forceFill(['email_verified_at' => now()])->saveQuietly();
            }

            return true;
        });
    }

    /** @return MorphMany<SignInCode, Model> */
    private function codes(Model $subject): MorphMany
    {
        return $subject->morphMany(SignInCode::class, 'subject');
    }

    /**
     * Keyed with the application key: six digits are trivial to brute-force from a plain hash,
     * not from an HMAC whose key is not in the database.
     */
    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
