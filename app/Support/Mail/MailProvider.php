<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * Sends a message and reports what happened.
 *
 * Deliberately returns a result rather than throwing on a rejected address: a bounced parent
 * email is an ordinary operational fact that belongs in the delivery record, not an exception
 * that aborts a run of forty reports (SL-OPS-007 §3).
 */
interface MailProvider
{
    public function send(MailMessage $message): MailResult;

    public function name(): string;
}
