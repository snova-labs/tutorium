<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Terminology\Terminology;

/**
 * Where a new tenant has got to.
 *
 * Every step is **derived from the data**, never tracked in a column. A checklist that stores its
 * own progress will eventually claim a step is done after someone deleted the thing it was about,
 * and a setup guide that lies is worse than none (FR-ONB-2).
 */
final class OnboardingChecklist
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly Terminology $terms,
    ) {}

    /** @return array<string, mixed> */
    public function for(Tenant $tenant): array
    {
        return $this->tenancy->runAs($tenant, function () use ($tenant): array {
            $steps = $this->steps();
            $done = collect($steps)->where('done', true)->count();

            return [
                'dismissed' => $tenant->onboarding_dismissed_at !== null,
                'complete' => $done === count($steps),
                'done' => $done,
                'total' => count($steps),
                // The point at which a teacher can take a register. Everything after it can wait
                // until after the first class if a customer is in a hurry (FR-ONB-4).
                'can_record_attendance' => collect($steps)->firstWhere('key', 'batch')['done'] ?? false,
                'steps' => $steps,
            ];
        });
    }

    public function dismiss(Tenant $tenant): void
    {
        $this->tenancy->withoutScoping(fn () => $tenant->update(['onboarding_dismissed_at' => now()]));
    }

    /** @return array<int, array<string, mixed>> */
    private function steps(): array
    {
        $brand = Brand::query()->first();
        $branch = Branch::query()->first();
        $course = Course::query()->first();
        $batch = Batch::query()->first();
        $hasTimetable = $batch !== null && TimetableSlot::query()->where('batch_id', $batch->getKey())->exists();
        $hasSessions = $batch !== null && ClassSession::query()->where('batch_id', $batch->getKey())->exists();

        return [
            [
                'key' => 'brand',
                'title' => 'Your details and logo',
                'hint' => 'The name and colours that appear on reports.',
                'done' => $brand !== null,
                'detail' => $brand?->name,
            ],
            [
                'key' => 'branch',
                'title' => 'First location',
                'hint' => 'Its timezone and week structure decide how everything beneath it is scheduled.',
                'done' => $branch !== null,
                'detail' => $branch === null ? null : $branch->name.' · '.$branch->timezone,
            ],
            [
                'key' => 'course',
                'title' => 'A '.$this->terms->lower('course'),
                'hint' => 'What you teach, and how often you report on it.',
                'done' => $course !== null,
                'detail' => $course?->name,
            ],
            [
                'key' => 'batch',
                'title' => 'A '.$this->terms->lower('batch').' with a timetable',
                'hint' => 'Sessions are generated from the timetable, not typed in one at a time.',
                'done' => $batch !== null && $hasTimetable && $hasSessions,
                'detail' => $this->batchDetail($batch, $hasTimetable, $hasSessions),
            ],
            [
                'key' => 'staff',
                'title' => 'Invite your teachers',
                'hint' => 'They will only see the '.$this->terms->lower('batch', true).' you assign them.',
                'done' => User::query()->count() > 1,
                'detail' => User::query()->count().' people',
            ],
            [
                'key' => 'learners',
                'title' => 'Add your '.$this->terms->lower('learner', true),
                'hint' => 'Type them in, or paste from a spreadsheet.',
                'done' => Enrollment::query()->exists(),
                'detail' => $this->terms->count('learner', Enrollment::query()->count()).' enrolled',
            ],
        ];
    }

    private function batchDetail(?Batch $batch, bool $hasTimetable, bool $hasSessions): ?string
    {
        if ($batch === null) {
            return null;
        }

        if (! $hasTimetable) {
            return $batch->name.' — needs a timetable';
        }

        if (! $hasSessions) {
            return $batch->name.' — timetable set, sessions not generated yet';
        }

        return $batch->name;
    }
}
