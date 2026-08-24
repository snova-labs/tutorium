<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Guardian;
use App\Models\Learner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class GuardianService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Guardian
    {
        return DB::transaction(fn (): Guardian => Guardian::query()->create($attributes));
    }

    /**
     * Link a guardian to a learner, reusing an existing guardian where the contact matches.
     *
     * Siblings are the common case: the second child should attach to the parent already on file,
     * not create a second copy whose email has to be corrected separately later.
     *
     * @param array<string, mixed> $attributes
     */
    public function attachOrCreate(Learner $learner, array $attributes, bool $isPrimary = false, bool $receivesReports = true): Guardian
    {
        return DB::transaction(function () use ($learner, $attributes, $isPrimary, $receivesReports): Guardian {
            $guardian = null;

            if (! empty($attributes['email'])) {
                $guardian = Guardian::query()->where('email', $attributes['email'])->first();
            }

            if ($guardian === null && ! empty($attributes['phone'])) {
                $guardian = Guardian::query()->where('phone', $attributes['phone'])->first();
            }

            $guardian ??= Guardian::query()->create($attributes);

            $this->link($learner, $guardian, $isPrimary, $receivesReports);

            return $guardian->refresh();
        });
    }

    public function link(Learner $learner, Guardian $guardian, bool $isPrimary = false, bool $receivesReports = true): void
    {
        DB::transaction(function () use ($learner, $guardian, $isPrimary, $receivesReports): void {
            if ($isPrimary) {
                $learner->guardians()->newPivotStatement()
                    ->where('learner_id', $learner->getKey())
                    ->update(['is_primary' => false]);
            }

            $learner->guardians()->syncWithoutDetaching([
                $guardian->getKey() => [
                    'tenant_id' => $learner->tenant_id,
                    'is_primary' => $isPrimary,
                    'receives_reports' => $receivesReports,
                ],
            ]);
        });
    }

    public function unlink(Learner $learner, Guardian $guardian): void
    {
        // A learner with nobody receiving their reports is a silent failure at report time, so it
        // is caught here instead.
        $remaining = $learner->guardians()
            ->wherePivot('receives_reports', true)
            ->whereKeyNot($guardian->getKey())
            ->count();

        if ($remaining === 0 && $learner->guardians()->count() > 1) {
            throw ValidationException::withMessages([
                'guardian' => 'Removing this guardian would leave nobody to receive reports for '
                    .$learner->displayName().'. Mark another guardian as a recipient first.',
            ]);
        }

        $learner->guardians()->detach($guardian->getKey());
    }

    public function setReportRecipient(Learner $learner, Guardian $guardian, bool $receives): void
    {
        $learner->guardians()->updateExistingPivot($guardian->getKey(), ['receives_reports' => $receives]);
    }
}
