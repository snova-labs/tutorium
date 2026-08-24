<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class StaffInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Invitation $invitation,
        private readonly string $token,
        private readonly string $academyName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.url'), '/').'/invitations/'.$this->token;

        return (new MailMessage)
            ->subject($this->academyName.' has invited you')
            ->greeting($this->invitation->name === null ? 'Hello.' : 'Hello '.$this->invitation->name.'.')
            ->line($this->academyName.' has invited you to join their account as '
                .$this->article($this->invitation->role_name).'.')
            ->action('Accept and set a password', $url)
            // Said plainly, because an invitation that arrives unexpectedly should be easy to
            // ignore rather than worrying.
            ->line('This invitation expires on '.$this->invitation->expires_at->toFormattedDateString()
                .'. If you were not expecting it, you can ignore this message — nothing has been '
                .'created in your name.')
            ->salutation('— '.$this->academyName);
    }

    private function article(string $role): string
    {
        return (str_contains('aeiou', strtolower($role[0])) ? 'an ' : 'a ').$role;
    }
}
