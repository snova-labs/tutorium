<?php

declare(strict_types=1);

namespace App\Support\Licensing;

use RuntimeException;

/**
 * Why a licence file could not be trusted, in words an administrator can act on.
 *
 * Every message names the file and what to do next, because the person reading it is usually
 * standing in front of an install that will not start and has no vendor on the phone.
 */
final class LicenceProblem extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self(sprintf('No licence file at %s. Put the file your licence came in there.', $path));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('The licence file at %s could not be read. Check its permissions.', $path));
    }

    public static function malformed(string $path, string $detail): self
    {
        return new self(sprintf('The licence file at %s is not a licence: %s.', $path, $detail));
    }

    public static function noPublicKey(): self
    {
        return new self(
            'This build ships no licence public key, so it cannot check whether a licence is genuine. '
            .'Set LICENCE_PUBLIC_KEY to the key published with your release.',
        );
    }

    public static function forged(string $path): self
    {
        // Deliberately not "corrupt": the signature failing means these bytes are not the bytes
        // that were signed, and an edited cap is far likelier than a bit flip.
        return new self(sprintf(
            'The signature on %s does not match its contents. The file has been changed since it was issued, '
            .'or it was issued for a different release.',
            $path,
        ));
    }
}
