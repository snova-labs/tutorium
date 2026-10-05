<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The link that creates the account (SL-402).
 *
 * Sent to an address, not a user: until it is used there is no user. The link opens a page that
 * asks for one click, so a mail scanner opening it does not create anything.
 */
final class ConfirmSignupNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $academy,
        private readonly int $hours,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('platform.web_url'), '/').'/sign-up/confirm/'.$this->token;

        return (new MailMessage)
            ->subject('Confirm your email to create '.$this->academy)
            ->greeting('Welcome.')
            ->line('Someone, hopefully you, asked to create an account for '.$this->academy.' with this address.')
            ->action('Confirm and create my account', $url)
            ->line(sprintf(
                'The link works once and expires in %d hours. Your trial starts when you confirm.',
                $this->hours,
            ))
            ->line('If this was not you, ignore this email: nothing has been created.')
            ->salutation('— '.config('platform.name', 'The team'));
    }
}
