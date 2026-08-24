<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A trial reminder that is useful rather than merely urgent.
 *
 * An academy that has not reached the point of taking a register needs help finishing setup, not a
 * countdown. An academy already using it needs to know what happens on the day and nothing else.
 */
final class TrialEndingNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly int $daysLeft,
        private readonly int $threshold,
        private readonly bool $readyToTeach,
        private readonly ?string $nextStep,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject());

        if (! $this->readyToTeach) {
            return $message
                ->greeting('Hello.')
                ->line(sprintf(
                    'Your trial ends in %s, and you have not reached the point where the product does '
                    .'anything useful for you yet.',
                    $this->daysLeft === 1 ? 'a day' : $this->daysLeft.' days',
                ))
                ->line($this->nextStep === null
                    ? 'The next step is adding a class with a timetable.'
                    : 'The next step is: '.$this->nextStep.'.')
                ->action('Finish setting up', rtrim((string) config('app.url'), '/').'/onboarding')
                ->line('If something is in the way, reply to this message and a person will read it.');
        }

        return $message
            ->greeting('Hello.')
            ->line(sprintf(
                'Your trial ends in %s.',
                $this->daysLeft === 1 ? 'a day' : $this->daysLeft.' days',
            ))
            ->line('If you add a payment method before then, nothing changes at all.')
            ->line('If you do not, your account becomes read-only: everything you have entered stays '
                .'exactly as it is, you can still read and export all of it, and adding a payment '
                .'method later restores full access immediately.')
            ->action('Add a payment method', rtrim((string) config('app.url'), '/').'/billing');
    }

    private function subject(): string
    {
        return match ($this->threshold) {
            1 => 'Your trial ends tomorrow',
            3 => 'Three days left on your trial',
            default => 'A week left on your trial',
        };
    }
}
