<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GradingSchemeKind;
use App\Enums\SessionStatus;
use App\Enums\Weekday;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceStatus;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Grade;
use App\Models\GradingScheme;
use App\Models\Guardian;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\SampleDataSet;
use App\Models\SessionType;
use App\Models\SubmissionStatus;
use App\Models\Tenant;
use App\Models\TimetableSlot;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A demonstration class, loadable and removable in one action.
 *
 * Someone evaluating the product should be able to see a finished report before entering a single
 * real learner. The manifest is what makes that safe: removal deletes exactly the ids the load
 * created, so it cannot take a real learner whose name happens to start with "Sample" (FR-ONB-3).
 */
final class SampleDataService
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function load(Tenant $tenant): SampleDataSet
    {
        return $this->tenancy->runAs($tenant, function (): SampleDataSet {
            if (SampleDataSet::query()->whereNull('removed_at')->exists()) {
                throw ValidationException::withMessages([
                    'sample' => 'Sample data is already loaded. Remove it before loading it again.',
                ]);
            }

            return DB::transaction(function (): SampleDataSet {
                // One manifest, one writer. Every created record goes through record(), which is
                // the only reason removal can be exact.
                $manifest = new SampleManifest;

                $branch = Branch::query()->firstOrFail();
                $batch = $this->structure($manifest, $branch);
                $sessions = $this->sessions($manifest, $batch, $branch);
                $enrollments = $this->people($manifest, $batch);

                $this->work($manifest, $batch, $enrollments);
                $this->marks($manifest, $sessions, $enrollments);

                return SampleDataSet::query()->create([
                    'label' => 'Sample class',
                    'created' => $manifest->all(),
                    'loaded_by' => Auth::id(),
                    'loaded_at' => now(),
                ]);
            });
        });
    }

    public function remove(Tenant $tenant): int
    {
        return $this->tenancy->runAs($tenant, function (): int {
            $set = SampleDataSet::query()->whereNull('removed_at')->latest('id')->first();

            if ($set === null) {
                throw ValidationException::withMessages(['sample' => 'There is no sample data to remove.']);
            }

            return DB::transaction(function () use ($set): int {
                $removed = 0;

                // Reverse order, so children go before the parents they point at.
                foreach (array_reverse($set->created) as [$class, $id]) {
                    /** @var Model|null $model */
                    $model = $class::query()->find($id);

                    if ($model === null) {
                        continue;
                    }

                    // Removes soft-deleting models outright too; on others it is a plain delete.
                    $model->forceDelete();
                    $removed++;
                }

                $set->update(['removed_at' => now()]);

                return $removed;
            });
        });
    }

    public function isLoaded(Tenant $tenant): bool
    {
        return $this->tenancy->runAs(
            $tenant,
            fn (): bool => SampleDataSet::query()->whereNull('removed_at')->exists(),
        );
    }

    private function structure(SampleManifest $manifest, Branch $branch): Batch
    {
        $course = $manifest->record(Course::query()->create([
            'brand_id' => $branch->brand_id,
            'name' => 'Sample Course',
            'code' => 'SAMPLE-CRS',
            'audience' => 'kids',
            'description' => 'A demonstration course. Remove it when you no longer need it.',
        ]));

        $month = CarbonImmutable::now($branch->timezone);

        $batch = $manifest->record(Batch::query()->create([
            'course_id' => $course->getKey(),
            'branch_id' => $branch->getKey(),
            'name' => 'Sample Class',
            'code' => 'SAMPLE-BAT',
            'timezone' => $branch->timezone,
            'starts_on' => $month->startOfMonth()->toDateString(),
            'ends_on' => $month->endOfMonth()->toDateString(),
            'capacity' => 20,
        ]));

        $manifest->record(TimetableSlot::query()->create([
            'batch_id' => $batch->getKey(),
            'session_type_id' => SessionType::query()->where('code', 'CLASS')->firstOrFail()->getKey(),
            'weekday' => Weekday::Saturday,
            'start_time_local' => '10:00:00',
            'duration_min' => 120,
        ]));

        return $batch->refresh();
    }

    /** @return Collection<int, ClassSession> */
    private function sessions(SampleManifest $manifest, Batch $batch, Branch $branch): Collection
    {
        $month = CarbonImmutable::now($branch->timezone);

        app(SessionGenerator::class)->generate($batch, $month->startOfMonth(), $month->endOfMonth());

        $sessions = ClassSession::query()->where('batch_id', $batch->getKey())->orderBy('session_local_date')->get();

        foreach ($sessions as $session) {
            $manifest->record($session);
        }

        return $sessions;
    }

    /** @return Collection<int, Enrollment> */
    private function people(SampleManifest $manifest, Batch $batch): Collection
    {
        $learnerStatus = LearnerStatus::query()->where('code', 'ACTIVE')->firstOrFail();
        $enrollStatus = EnrollmentStatus::query()->where('code', 'ACTIVE')->firstOrFail();
        $enrollments = collect();

        foreach (range(1, 6) as $i) {
            $learner = $manifest->record(Learner::query()->create([
                'number' => 'SAMPLE-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'legal_name' => 'Sample Learner '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status_id' => $learnerStatus->getKey(),
                'status_changed_on' => now()->toDateString(),
            ]));

            $guardian = $manifest->record(Guardian::query()->create([
                'name' => 'Sample Guardian '.chr(64 + $i),
                'email' => 'sample.guardian.'.strtolower(chr(64 + $i)).'@example.test',
            ]));

            $learner->guardians()->attach($guardian->getKey(), [
                'tenant_id' => $learner->tenant_id, 'is_primary' => true, 'receives_reports' => true,
            ]);

            $enrollments->push($manifest->record(Enrollment::query()->create([
                'learner_id' => $learner->getKey(),
                'batch_id' => $batch->getKey(),
                'number' => 'SAMPLE-ENR-'.$i,
                'enrolled_on' => $batch->starts_on->toDateString(),
                'status_id' => $enrollStatus->getKey(),
            ])));
        }

        return $enrollments;
    }

    /** @param Collection<int, Enrollment> $enrollments */
    private function work(SampleManifest $manifest, Batch $batch, Collection $enrollments): void
    {
        $scheme = GradingScheme::query()->where('kind', GradingSchemeKind::Points->value)->first();
        $type = AssessmentType::query()->first();
        $submitted = SubmissionStatus::query()->where('code', 'SUBMITTED')->first();

        if ($scheme === null || $type === null || $submitted === null) {
            return;
        }

        $assessment = $manifest->record(Assessment::query()->create([
            'batch_id' => $batch->getKey(),
            'assessment_type_id' => $type->getKey(),
            'grading_scheme_id' => $scheme->getKey(),
            'number' => 'SAMPLE-ASM-1',
            'title' => 'Sample worksheet',
            'due_local_date' => $batch->starts_on->addDays(14)->toDateString(),
            'max_points' => 20,
            'is_published' => true,
        ]));

        foreach ($enrollments as $i => $enrollment) {
            $manifest->record(Grade::query()->create([
                'assessment_id' => $assessment->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'submission_status_id' => $submitted->getKey(),
                'raw_score' => 20 - $i,
                'normalized_pct' => ((20 - $i) / 20) * 100,
                'graded_at' => now(),
            ]));
        }
    }

    /**
     * @param Collection<int, ClassSession> $sessions
     * @param Collection<int, Enrollment> $enrollments
     */
    private function marks(SampleManifest $manifest, Collection $sessions, Collection $enrollments): void
    {
        $present = AttendanceStatus::query()->where('code', 'PRESENT')->first();
        $absent = AttendanceStatus::query()->where('code', 'ABSENT')->first();

        if ($present === null) {
            return;
        }

        // The last session stays unmarked on purpose, so a demonstration shows the "not yet
        // marked" state rather than an unrealistically tidy month.
        foreach ($sessions->take(max(0, $sessions->count() - 1)) as $index => $session) {
            $session->update(['status' => SessionStatus::Held]);

            foreach ($enrollments as $i => $enrollment) {
                $status = ($i === 2 && $index === 1 && $absent !== null) ? $absent : $present;

                $manifest->record(AttendanceRecord::query()->create([
                    'class_session_id' => $session->getKey(),
                    'enrollment_id' => $enrollment->getKey(),
                    'status_id' => $status->getKey(),
                    'recorded_at' => now(),
                ]));
            }
        }
    }
}
