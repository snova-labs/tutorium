<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\SessionStatus;
use App\Models\AttendanceRecord;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Support\Tenancy\TenantContext;
use App\Support\Terminology\Terminology;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The home screen: what is happening today, and what is in the way.
 *
 * Deliberately not a dashboard of metrics. The question a teacher opens this
 * with is "what do I need to do now", and a wall of percentages answers a
 * different one.
 */
final class Today extends Component
{
    /** @return array<int, array<string, mixed>> */
    #[Computed]
    public function sessions(): array
    {
        $user = auth()->user();

        $batches = Batch::query()
            ->with('branch')
            ->when(
                ! $user->can('batches.manage'),
                fn ($q) => $q->whereHas('teachers', fn ($t) => $t->whereKey($user->getKey())),
            )
            ->get()
            ->keyBy('id');

        if ($batches->isEmpty()) {
            return [];
        }

        // A day is a local thing. Querying "today" in UTC would drop a Kathmandu
        // evening class and add a Toronto one that has not happened yet, which is
        // exactly the mistake this whole design exists to avoid.
        $sessions = ClassSession::query()
            ->with(['sessionType', 'batch'])
            ->whereIn('batch_id', $batches->keys())
            ->whereIn('status', [SessionStatus::Scheduled, SessionStatus::Held])
            ->get()
            ->filter(function (ClassSession $session) use ($batches): bool {
                $zone = $batches[$session->batch_id]->timezone;

                return $session->session_local_date->toDateString()
                    === CarbonImmutable::now($zone)->toDateString();
            })
            ->sortBy('starts_at_utc');

        $marked = AttendanceRecord::query()
            ->whereIn('class_session_id', $sessions->modelKeys())
            ->selectRaw('class_session_id, COUNT(*) as marks')
            ->groupBy('class_session_id')
            ->pluck('marks', 'class_session_id');

        $viewerZone = $user->timezone;

        return $sessions->map(function (ClassSession $session) use ($marked, $viewerZone): array {
            $zone = $session->batch->timezone;

            return [
                'id' => $session->getKey(),
                'batch' => $session->batch->name,
                'type' => $session->sessionType->name,
                'local_time' => substr((string) $session->start_time_local, 0, 5),
                'local_zone' => $zone,
                'viewer_time' => $viewerZone && $viewerZone !== $zone
                    ? $session->startsAtIn($viewerZone)->format('H:i')
                    : null,
                'viewer_zone' => $viewerZone && $viewerZone !== $zone ? $viewerZone : null,
                'marked' => (int) ($marked[$session->getKey()] ?? 0),
                'held' => $session->status === SessionStatus::Held,
                'past' => $session->starts_at_utc->isPast(),
            ];
        })->values()->all();
    }

    /**
     * Registers from earlier in the week that were never marked.
     *
     * Surfaced here because an unmarked session is invisible everywhere else
     * until a report is generated over it and the gap becomes a parent's
     * question.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function unmarked(): array
    {
        $user = auth()->user();

        return ClassSession::query()
            ->with('batch')
            ->where('status', SessionStatus::Scheduled)
            ->where('starts_at_utc', '<', now())
            ->where('starts_at_utc', '>', now()->subDays(14))
            ->when(
                ! $user->can('batches.manage'),
                fn ($q) => $q->whereHas('batch.teachers', fn ($t) => $t->whereKey($user->getKey())),
            )
            ->orderBy('starts_at_utc')
            ->limit(5)
            ->get()
            ->map(fn (ClassSession $s) => [
                'id' => $s->getKey(),
                'batch' => $s->batch->name,
                'date' => $s->session_local_date->format('D j M'),
                'days_ago' => (int) $s->session_local_date->diffInDays(now()),
            ])
            ->all();
    }

    #[Computed]
    public function terms(): Terminology
    {
        return app(Terminology::class);
    }

    public function render(): View
    {
        return view('livewire.today', [
            'tenant' => app(TenantContext::class)->get(),
        ])->layout('layouts.app');
    }
}
