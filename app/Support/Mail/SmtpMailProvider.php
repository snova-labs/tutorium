<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Throwable;

/** The default: whatever SMTP the environment is configured with — Mailpit in development. */
final class SmtpMailProvider implements MailProvider
{
    public function __construct(private readonly Mailer $mailer) {}

    public function send(MailMessage $message): MailResult
    {
        try {
            $this->mailer->html($message->html, function (Message $mail) use ($message): void {
                $mail->to($message->to, $message->toName);
                $mail->subject($message->subject);

                if ($message->fromAddress !== null) {
                    $mail->from($message->fromAddress, $message->fromName);
                }

                // Replies belong to the academy, never to us.
                if ($message->replyTo !== null) {
                    $mail->replyTo($message->replyTo);
                }

                foreach ($message->attachments as $attachment) {
                    $mail->attachData($attachment['content'], $attachment['name'], [
                        'mime' => $attachment['mime'],
                    ]);
                }
            });

            return MailResult::sent($this->name());
        } catch (Throwable $e) {
            return MailResult::failed($this->name(), $e->getMessage());
        }
    }

    public function name(): string
    {
        return 'smtp';
    }
}
