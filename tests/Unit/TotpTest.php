<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Security\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Checked against the published vectors rather than against itself, because a TOTP that agrees
 * only with its own output would still lock every operator out of a real authenticator app.
 */
final class TotpTest extends TestCase
{
    /** "12345678901234567890", the RFC 6238 SHA-1 test key, in base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return array<string, array{int, string}> */
    public static function rfc6238(): array
    {
        return [
            'T=59' => [59, '94287082'],
            'T=1111111109' => [1111111109, '07081804'],
            'T=1111111111' => [1111111111, '14050471'],
            'T=1234567890' => [1234567890, '89005924'],
            'T=2000000000' => [2000000000, '69279037'],
            'T=20000000000' => [20000000000, '65353130'],
        ];
    }

    #[Test]
    #[DataProvider('rfc6238')]
    public function it_matches_the_rfc_6238_test_vectors(int $time, string $expected): void
    {
        $totp = new Totp;

        $this->assertSame($expected, $totp->codeAt(self::RFC_SECRET, $totp->currentStep($time), 8));
    }

    #[Test]
    public function base32_round_trips(): void
    {
        $totp = new Totp;

        $this->assertSame(self::RFC_SECRET, $totp->base32Encode('12345678901234567890'));
        $this->assertSame('12345678901234567890', $totp->base32Decode(self::RFC_SECRET));
    }

    #[Test]
    public function a_code_from_the_neighbouring_step_is_accepted_for_clock_drift(): void
    {
        $totp = new Totp;
        $secret = $totp->generateSecret();
        $now = 1_700_000_000;
        $previous = $totp->codeAt($secret, $totp->currentStep($now) - 1);

        $this->assertSame($totp->currentStep($now) - 1, $totp->verify($secret, $previous, null, $now));
        $this->assertNull($totp->verify($secret, $totp->codeAt($secret, $totp->currentStep($now) - 2), null, $now));
    }

    #[Test]
    public function a_code_is_not_accepted_twice(): void
    {
        $totp = new Totp;
        $secret = $totp->generateSecret();
        $now = 1_700_000_000;
        $code = $totp->codeAt($secret, $totp->currentStep($now));

        $step = $totp->verify($secret, $code, null, $now);

        $this->assertNotNull($step);
        $this->assertNull($totp->verify($secret, $code, $step, $now), 'A replayed code must be refused.');
    }

    #[Test]
    public function malformed_input_is_refused_rather_than_compared(): void
    {
        $totp = new Totp;
        $secret = $totp->generateSecret();

        $this->assertNull($totp->verify($secret, 'abc123'));
        $this->assertNull($totp->verify($secret, '12345'));
    }

    #[Test]
    public function the_provisioning_uri_carries_what_an_authenticator_needs(): void
    {
        $uri = (new Totp)->provisioningUri(self::RFC_SECRET, 'ops@sample.test', 'Platform operator console');

        $this->assertStringStartsWith('otpauth://totp/Platform%20operator%20console:ops%40sample.test?', $uri);
        $this->assertStringContainsString('secret='.self::RFC_SECRET, $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
