<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirm the address, without holding the product hostage to it.
 *
 * The wording says what verification unlocks rather than implying the account is unusable, because
 * it is not — the register works from the moment they sign up.
 */
final class VerifyEmailNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.url'), '/').'/signup/verify/'.$this->token;

        return (new MailMessage)
            ->subject('Confirm your email address')
            ->greeting('Welcome.')
            ->line('Confirm this address so that reports and notices can be sent to families from your academy.')
            ->action('Confirm my address', $url)
            ->line('You can carry on setting things up in the meantime — everything works apart from '
                .'sending email to parents.')
            ->line('This link is valid for 48 hours and can only be used once.')
            ->salutation('— '.config('platform.name', 'The team'));
    }
}
