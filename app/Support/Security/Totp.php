<?php

declare(strict_types=1);

namespace App\Support\Security;

use InvalidArgumentException;

/**
 * Time-based one-time passwords (RFC 6238, the scheme authenticator apps implement).
 *
 * Kept in-house rather than pulled in as a dependency because it is small, has published test
 * vectors, and sits on the path that guards every customer's data: there is less to trust.
 * SHA-1, six digits and thirty-second steps are what authenticator apps assume by default.
 */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /** Steps either side of now that still count, to absorb clock drift between phone and server. */
    public const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random secret, base32-encoded as authenticator apps expect. 160 bits, per RFC 4226. */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /** The code for a time step (Unix time divided by the period). */
    public function codeAt(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $this->base32Decode($secret), true);

        // Dynamic truncation (RFC 4226 §5.3).
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public function currentStep(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD);
    }

    /**
     * The step a code belongs to, or null if it matches none in the window.
     *
     * Steps at or before $lastUsedStep are refused, so a code seen over someone's shoulder cannot
     * be used a second time within its thirty seconds.
     */
    public function verify(string $secret, string $code, ?int $lastUsedStep = null, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }

        $now = $this->currentStep($timestamp);

        for ($step = $now - self::WINDOW; $step <= $now + self::WINDOW; $step++) {
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }

            if (hash_equals($this->codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** The link an authenticator app reads from a QR code. */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?%s',
            rawurlencode($issuer),
            rawurlencode($account),
            http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986),
        );
    }

    public function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    public function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim($encoded, '='));
        $bits = '';

        foreach (str_split($encoded) as $char) {
            $value = strpos(self::BASE32, $char);

            if ($value === false) {
                throw new InvalidArgumentException('The secret is not valid base32.');
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
