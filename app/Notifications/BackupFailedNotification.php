<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BackupRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class BackupFailedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly BackupRun $run) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $what = $this->run->type === BackupRun::DRILL ? 'restore drill' : 'nightly backup';

        $message = (new MailMessage)
            ->error()
            ->subject(sprintf('[%s] The %s failed', config('platform.name'), $what))
            ->line(sprintf('The %s %s failed at %s UTC.', $what, $this->run->name ?? '', $this->run->started_at->utc()->format('Y-m-d H:i')))
            ->line('Reason: '.($this->run->error ?? 'unknown'));

        foreach (array_slice((array) ($this->run->details['problems'] ?? []), 0, 10) as $problem) {
            $message->line('- '.$problem);
        }

        return $message
            ->line('Check with `php artisan platform:backups` in the API container, and see "Backups" in docs/DEPLOYMENT.md.');
    }
}
