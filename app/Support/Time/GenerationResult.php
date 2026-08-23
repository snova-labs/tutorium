<?php

declare(strict_types=1);

namespace App\Support\Time;

use App\Models\ClassSession;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * What a generation run did, and — more usefully — what it declined to do.
 *
 * Generation that silently skips things is how a batch ends up missing three weeks that nobody
 * notices until the reports look wrong, so every omission is named and reasoned.
 *
 * @implements Arrayable<string, mixed>
 */
final class GenerationResult implements Arrayable
{
    /** @var Collection<int, ClassSession> */
    public Collection $created;

    /** @var array<int, array{date: string, time: string, reason: string, detail?: string}> */
    public array $skipped = [];

    public function __construct()
    {
        $this->created = collect();
    }

    public function add(ClassSession $session): void
    {
        $this->created->push($session);
    }

    public function skip(string $date, string $time, string $reason, ?string $detail = null): void
    {
        $entry = ['date' => $date, 'time' => $time, 'reason' => $reason];

        if ($detail !== null) {
            $entry['detail'] = $detail;
        }

        $this->skipped[] = $entry;
    }

    public function createdCount(): int
    {
        return $this->created->count();
    }

    /** @return array<int, array<string, string>> */
    public function skippedFor(string $reason): array
    {
        return array_values(array_filter($this->skipped, fn (array $s) => $s['reason'] === $reason));
    }

    /** A sentence a coordinator can read without opening the detail. */
    public function summary(): string
    {
        $parts = [sprintf('%d session%s created', $this->createdCount(), $this->createdCount() === 1 ? '' : 's')];

        foreach (['already_exists' => 'already scheduled', 'holiday' => 'on a closure date',
            'outside_batch_dates' => 'outside the batch dates', 'slot_not_effective' => 'outside the slot dates',
            'clock_change' => 'skipped over a clock change'] as $reason => $phrase) {
            $count = count($this->skippedFor($reason));

            if ($count > 0) {
                $parts[] = "{$count} {$phrase}";
            }
        }

        return implode(', ', $parts).'.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'created' => $this->createdCount(),
            'skipped' => $this->skipped,
            'summary' => $this->summary(),
        ];
    }
}
