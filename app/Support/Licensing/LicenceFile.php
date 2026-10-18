<?php

declare(strict_types=1);

namespace App\Support\Licensing;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use SodiumException;

/**
 * Reading a licence file, and proving it was issued by us.
 *
 * The file is a small JSON envelope:
 *
 *     {"format": 1, "payload": "<base64 of the licence JSON>", "signature": "<base64 signature>"}
 *
 * The payload travels base64-encoded on purpose. The signature covers those exact bytes, so there
 * is no canonical-JSON question to get wrong later: whatever we signed is what is verified, down
 * to the whitespace. Ed25519 signatures are 64 bytes and verification is local arithmetic — no
 * clock, no network, no licence server to be unreachable at four in the morning (NFR-PORT-2).
 */
final class LicenceFile
{
    /** The only envelope version this release understands. */
    public const FORMAT = 1;

    /**
     * Read and verify the licence at a path.
     *
     * @throws LicenceProblem when the file is absent, unreadable, not a licence, or not ours
     */
    public static function read(string $path, string $publicKeyHex, int $graceDays = 30): Licence
    {
        if (! is_file($path)) {
            throw LicenceProblem::missing($path);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw LicenceProblem::unreadable($path);
        }

        $envelope = json_decode($contents, true);

        if (! is_array($envelope)) {
            throw LicenceProblem::malformed($path, 'it is not JSON');
        }

        if (($envelope['format'] ?? null) !== self::FORMAT) {
            throw LicenceProblem::malformed($path, sprintf(
                'it is format %s and this release reads format %d',
                json_encode($envelope['format'] ?? null),
                self::FORMAT,
            ));
        }

        $payload = base64_decode((string) ($envelope['payload'] ?? ''), true);
        $signature = base64_decode((string) ($envelope['signature'] ?? ''), true);

        if ($payload === false || $payload === '' || $signature === false) {
            throw LicenceProblem::malformed($path, 'its payload or signature is not base64');
        }

        self::verify($path, $payload, $signature, $publicKeyHex);

        $claims = json_decode($payload, true);

        if (! is_array($claims)) {
            throw LicenceProblem::malformed($path, 'its payload is not JSON');
        }

        return self::licence($path, $claims, $graceDays);
    }

    /**
     * Sign a licence payload, producing the file's contents.
     *
     * Vendor-side: the secret key never goes near an install, and nothing in the running product
     * calls this. It lives here so that the thing we issue and the thing we verify are read from
     * one description of the format rather than two.
     *
     * @param array<string, mixed> $claims
     */
    public static function sign(array $claims, string $secretKeyHex): string
    {
        $payload = (string) json_encode($claims, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $secret = self::key($secretKeyHex, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'secret');

        return (string) json_encode([
            'format' => self::FORMAT,
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $secret)),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * A fresh signing keypair, as hex. Vendor-side, run once.
     *
     * @return array{0: string, 1: string} the public key, then the secret key
     */
    public static function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            bin2hex(sodium_crypto_sign_publickey($pair)),
            bin2hex(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    private static function verify(string $path, string $payload, string $signature, string $publicKeyHex): void
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            // composer.json requires ext-sodium, so this is an install that got past that. Saying
            // so beats a fatal error about an undefined function.
            throw new LicenceProblem(
                'This PHP has no sodium extension, so no licence signature can be checked. Install ext-sodium.',
            );
        }

        $key = self::key($publicKeyHex, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'public');

        try {
            $genuine = sodium_crypto_sign_verify_detached($signature, $payload, $key);
        } catch (SodiumException) {
            // A signature of the wrong length is not a signature of ours.
            $genuine = false;
        }

        if (! $genuine) {
            throw LicenceProblem::forged($path);
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function licence(string $path, array $claims, int $graceDays): Licence
    {
        foreach (['id', 'licensee', 'issued_on', 'expires_on'] as $required) {
            if (! isset($claims[$required]) || $claims[$required] === '') {
                throw LicenceProblem::malformed($path, sprintf('it has no %s', str_replace('_', ' ', $required)));
            }
        }

        $features = $claims['features'] ?? [];

        if (! is_array($features)) {
            throw LicenceProblem::malformed($path, 'its features are not a list');
        }

        try {
            $issuedOn = CarbonImmutable::parse((string) $claims['issued_on'])->startOfDay();
            $expiresOn = CarbonImmutable::parse((string) $claims['expires_on'])->startOfDay();
        } catch (InvalidFormatException) {
            throw LicenceProblem::malformed($path, 'its dates are not dates');
        }

        if ($expiresOn->lt($issuedOn)) {
            throw LicenceProblem::malformed($path, 'it expires before it was issued');
        }

        return new Licence(
            id: (string) $claims['id'],
            licensee: (string) $claims['licensee'],
            issuedOn: $issuedOn,
            expiresOn: $expiresOn,
            // Absent means unlimited, which is a different thing from a cap of zero.
            maxLearners: array_key_exists('max_learners', $claims) && $claims['max_learners'] !== null
                ? (int) $claims['max_learners']
                : null,
            features: $features,
            notes: isset($claims['notes']) ? (string) $claims['notes'] : null,
            graceDays: $graceDays,
        );
    }

    private static function key(string $hex, int $bytes, string $which): string
    {
        if ($hex === '') {
            throw LicenceProblem::noPublicKey();
        }

        $key = @hex2bin($hex);

        if ($key === false || strlen($key) !== $bytes) {
            throw new LicenceProblem(sprintf(
                'The licence %s key is not %d hex characters. Copy it exactly as it was published.',
                $which,
                $bytes * 2,
            ));
        }

        return $key;
    }
}
