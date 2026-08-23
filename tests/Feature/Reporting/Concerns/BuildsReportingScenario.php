<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Concerns;

use App\Enums\GradingSchemeKind;
use App\Enums\SessionStatus;
use App\Enums\Weekday;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceStatus;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Guardian;
use App\Models\GradingScheme;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\NoteCategory;
use App\Models\SessionType;
use App\Models\SubmissionStatus;
use App\Models\Tenant;
use App\Models\TimetableSlot;
use App\Services\AssessmentService;
use App\Services\GradeBookService;
use App\Services\TeacherNoteService;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\SessionGenerator;
use Carbon\CarbonImmutable;

/**
 * One learner, one batch, one August: enough for a report and nothing more.
 */
trait BuildsReportingScenario
{
    protected Tenant $tenant;

    protected Batch $batch;

    protected Enrollment $enrollment;

    protected Assessment $homework;

    protected Guardian $guardian;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::factory()->create(['slug' => 'reporting-'.uniqid()])
        );

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->lookups();
            $this->scenario();
        });
    }

    protected function learnerName(): string
    {
        return $this->enrollment->learner->displayName();
    }

    protected function markAllPresent(): void
    {
        $present = AttendanceStatus::query()->where('code', 'PRESENT')->first();

        foreach (ClassSession::query()->where('batch_id', $this->batch->getKey())->get() as $session) {
            $session->update(['status' => SessionStatus::Held]);

            AttendanceRecord::query()->updateOrCreate(
                ['class_session_id' => $session->getKey(), 'enrollment_id' => $this->enrollment->getKey()],
                ['status_id' => $present->getKey(), 'recorded_at' => now()],
            );
        }
    }

    protected function gradeHomework(float $score): void
    {
        app(GradeBookService::class)->saveGrid($this->batch, [[
            'assessment_id' => $this->homework->getKey(),
            'enrollment_id' => $this->enrollment->getKey(),
            'submission_status_id' => SubmissionStatus::query()->where('code', 'SUBMITTED')->first()->getKey(),
            'raw_score' => $score,
        ]]);
    }

    protected function writeNote(string $body, bool $visible): void
    {
        app(TeacherNoteService::class)->write($this->enrollment->load('batch.course'), [
            'note_category_id' => NoteCategory::query()->where('code', 'GENERAL')->first()->getKey(),
            'body' => $body,
            'is_report_visible' => $visible,
            'period' => '2026-08',
        ]);
    }

    private function lookups(): void
    {
        LearnerStatus::query()->firstOrCreate(['code' => 'ACTIVE'], ['name' => 'Active', 'sort' => 0]);
        EnrollmentStatus::query()->firstOrCreate(['code' => 'ACTIVE'],
            ['name' => 'Active', 'is_active_for_billing' => true, 'sort' => 0]);
        AttendanceStatus::query()->firstOrCreate(['code' => 'PRESENT'],
            ['name' => 'Present', 'counts_as_attended' => true, 'counts_in_rate' => true]);
        SubmissionStatus::query()->firstOrCreate(['code' => 'SUBMITTED'],
            ['name' => 'Submitted', 'counts_as_submitted' => true]);
        NoteCategory::query()->firstOrCreate(['code' => 'GENERAL'],
            ['name' => 'General', 'report_visible_default' => true, 'sort' => 0]);
        NoteCategory::query()->firstOrCreate(['code' => 'BEHAVIOUR'],
            ['name' => 'Behaviour', 'report_visible_default' => false, 'sort' => 1]);
    }

    private function scenario(): void
    {
        $brand = Brand::factory()->create(['sender_email' => 'reports@sample.test', 'sender_name' => 'Sample Brand']);
        $branch = Branch::factory()->create(['brand_id' => $brand->getKey(), 'timezone' => 'Asia/Kathmandu']);
        $course = Course::factory()->create(['brand_id' => $brand->getKey()]);

        $this->batch = Batch::factory()->create([
            'course_id' => $course->getKey(),
            'branch_id' => $branch->getKey(),
            'timezone' => 'Asia/Kathmandu',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
        ]);

        TimetableSlot::query()->create([
            'batch_id' => $this->batch->getKey(),
            'session_type_id' => SessionType::factory()->create(['code' => 'CLASS'])->getKey(),
            'weekday' => Weekday::Saturday,
            'start_time_local' => '10:00:00',
            'duration_min' => 120,
        ]);

        app(SessionGenerator::class)->generate(
            $this->batch,
            CarbonImmutable::parse('2026-08-01', 'Asia/Kathmandu'),
            CarbonImmutable::parse('2026-08-31', 'Asia/Kathmandu'),
        );

        $learner = Learner::factory()->create([
            'status_id' => LearnerStatus::query()->where('code', 'ACTIVE')->first()->getKey(),
        ]);

        $this->guardian = Guardian::factory()->create(['name' => 'Sample Guardian A']);
        $learner->guardians()->attach($this->guardian->getKey(), [
            'tenant_id' => $learner->tenant_id, 'is_primary' => true, 'receives_reports' => true,
        ]);

        $this->enrollment = Enrollment::factory()->create([
            'learner_id' => $learner->getKey(),
            'batch_id' => $this->batch->getKey(),
            'status_id' => EnrollmentStatus::query()->where('code', 'ACTIVE')->first()->getKey(),
            'enrolled_on' => '2026-08-01',
        ]);

        $scheme = GradingScheme::query()->create([
            'name' => 'Points', 'code' => 'POINTS', 'kind' => GradingSchemeKind::Points, 'config' => [],
        ]);

        $type = AssessmentType::query()->firstOrCreate(['code' => 'HOMEWORK'], ['name' => 'Homework']);

        $this->homework = app(AssessmentService::class)->publish(
            app(AssessmentService::class)->create($this->batch, [
                'assessment_type_id' => $type->getKey(),
                'grading_scheme_id' => $scheme->getKey(),
                'title' => 'Sample worksheet',
                'due_local_date' => '2026-08-15',
                'max_points' => 20,
            ])
        );
    }
}
