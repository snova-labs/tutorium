<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * A message to send, independent of who sends it.
 *
 * @param array<int, array{name: string, content: string, mime: string}> $attachments
 */
final class MailMessage
{
    /** @param array<int, array{name: string, content: string, mime: string}> $attachments */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly ?string $toName = null,
        public readonly ?string $fromAddress = null,
        public readonly ?string $fromName = null,
        public readonly ?string $replyTo = null,
        public readonly array $attachments = [],
    ) {}
}
