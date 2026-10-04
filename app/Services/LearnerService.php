<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Support\Sequences\IdSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LearnerService
{
    public function __construct(private readonly IdSequenceService $sequences) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Learner
    {
        return DB::transaction(function () use ($attributes): Learner {
            // Issued inside the transaction so a rolled-back create does not silently consume a
            // number and leave a gap nobody can explain.
            $attributes['number'] ??= $this->sequences->next('learner');
            $attributes['status_id'] ??= $this->defaultStatus()->getKey();
            $attributes['status_changed_on'] ??= now()->toDateString();

            $learner = new Learner;
            $learner->fill($attributes)->save();

            return $learner;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Learner $learner, array $attributes): Learner
    {
        // The identifier is what appears on every report already sent home. It is assigned once.
        unset($attributes['number']);

        return DB::transaction(function () use ($learner, $attributes): Learner {
            $learner->update($attributes);

            return $learner->refresh();
        });
    }

    public function changeStatus(Learner $learner, LearnerStatus $status, ?string $reason = null): Learner
    {
        return DB::transaction(function () use ($learner, $status, $reason): Learner {
            $learner->update([
                'status_id' => $status->getKey(),
                'status_reason' => $reason,
                'status_changed_on' => now()->toDateString(),
            ]);

            return $learner->refresh();
        });
    }

    /**
     * Permanent removal, for a data-subject request rather than for tidying up.
     *
     * Refused while academic history exists: deleting the learner would orphan attendance and
     * grades that a report has already been built on. Erasure of that history is a separate,
     * deliberate workflow (SL-SEC-004 §8).
     */
    public function forceDelete(Learner $learner): void
    {
        if ($learner->enrollments()->exists()) {
            throw ValidationException::withMessages([
                'learner' => 'This learner has enrollment history. Archive them instead, or use the '
                    .'erasure workflow if this is a data-protection request.',
            ]);
        }

        DB::transaction(function () use ($learner): void {
            $learner->guardians()->detach();
            $learner->forceDelete();
        });
    }

    private function defaultStatus(): LearnerStatus
    {
        return LearnerStatus::query()->where('code', 'ACTIVE')->firstOr(
            fn () => LearnerStatus::query()->orderBy('sort')->firstOrFail(),
        );
    }
}
