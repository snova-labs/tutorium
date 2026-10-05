<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BackupRun;
use App\Models\Operator;
use App\Notifications\BackupFailedNotification;
use Illuminate\Support\Facades\Notification;

/** Tells a person when a backup or a restore drill fails. A failure nobody hears about is a hope too. */
final class BackupAlerts
{
    public function failed(BackupRun $run): void
    {
        $notification = new BackupFailedNotification($run);
        $to = trim((string) config('backup.alert_to'));

        if ($to !== '') {
            Notification::route('mail', array_map(trim(...), explode(',', $to)))->notify($notification);

            return;
        }

        Notification::send(Operator::query()->where('is_active', true)->get(), $notification);
    }
}
