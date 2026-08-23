<?php

declare(strict_types=1);

namespace App\Support\Mail;

final class MailResult
{
    private function __construct(
        public readonly bool $sent,
        public readonly string $provider,
        public readonly ?string $reference = null,
        public readonly ?string $error = null,
    ) {}

    public static function sent(string $provider, ?string $reference = null): self
    {
        return new self(true, $provider, $reference);
    }

    public static function failed(string $provider, string $error): self
    {
        return new self(false, $provider, null, $error);
    }
}
