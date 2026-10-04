<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The emailed second step of signing in.
 *
 * Sent straight away rather than queued: someone is waiting at the sign-in screen for it.
 */
final class SignInCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        private readonly int $minutesValid,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your sign-in code: '.$this->code)
            ->line('Enter this code to finish signing in:')
            ->line('**'.$this->code.'**')
            ->line("It works once and expires in {$this->minutesValid} minutes.")
            ->line('If you were not signing in just now, someone has your password. Change it, '
                .'and tell whoever looks after your account.')
            ->salutation('— '.config('platform.name', 'The team'));
    }
}
