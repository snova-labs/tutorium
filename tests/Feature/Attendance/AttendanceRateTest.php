<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Enums\SessionStatus;
use App\Enums\Weekday;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\AttendanceStatus;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\SessionType;
use App\Models\Tenant;
use App\Models\TimetableSlot;
use App\Support\Attendance\AttendanceCalculator;
use App\Support\Attendance\AttendancePolicyResolver;
use App\Support\Attendance\AttendanceRate;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\PeriodService;
use App\Support\Time\SessionGenerator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The arithmetic every report and every dashboard is built on.
 *
 * SL-SRS-001 §7.1 acceptance: "a student marked Late in a late-join-allowed batch counts as
 * attended in the percentage" — and, just as importantly, stops counting when that policy changes
 * without any mark being edited.
 */
final class AttendanceRateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::factory()->create(['slug' => 'attendance-test']),
        );
    }

    #[Test]
    public function late_counts_as_attended_where_the_policy_allows_it(): void
    {
        $this->inTenant(function (): void {
            [$batch, $enrollment, $sessions] = $this->fiveHeldSessions();
            $statuses = $this->statuses();

            // Present, Present, Late, Present, Absent
            $this->mark($enrollment, $sessions[0], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[1], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[2], $statuses['LATE'], minutesLate: 7);
            $this->mark($enrollment, $sessions[3], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[4], $statuses['ABSENT']);

            $rate = $this->rateFor($enrollment, $batch);

            $this->assertSame(4, $rate->attended);
            $this->assertSame(5, $rate->counted);
            $this->assertSame(80.0, $rate->percentage());
            $this->assertTrue($rate->isComplete());
        });
    }

    #[Test]
    public function the_same_marks_read_differently_when_late_join_is_switched_off(): void
    {
        $this->inTenant(function (): void {
            [$batch, $enrollment, $sessions] = $this->fiveHeldSessions();
            $statuses = $this->statuses();

            $this->mark($enrollment, $sessions[0], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[1], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[2], $statuses['LATE']);
            $this->mark($enrollment, $sessions[3], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[4], $statuses['ABSENT']);

            $this->assertSame(80.0, $this->rateFor($enrollment, $batch)->percentage());

            AttendancePolicy::query()->create([
                'scope_type' => 'batch',
                'scope_id' => $batch->getKey(),
                'allow_late_join' => false,
            ]);

            app(AttendancePolicyResolver::class)->forget();

            // Not one mark changed. The policy decides what they mean.
            $this->assertSame(60.0, $this->rateFor($enrollment, $batch)->percentage());
            $this->assertSame(
                5,
                AttendanceRecord::query()->where('enrollment_id', $enrollment->getKey())->count(),
            );
        });
    }

    #[Test]
    public function excused_leaves_the_denominator_rather_than_scoring_zero(): void
    {
        $this->inTenant(function (): void {
            [$batch, $enrollment, $sessions] = $this->fiveHeldSessions();
            $statuses = $this->statuses();

            $this->mark($enrollment, $sessions[0], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[1], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[2], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[3], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[4], $statuses['EXCUSED']);

            $rate = $this->rateFor($enrollment, $batch);

            // Four of four, not four of five. A learner excused from a class is not absent from it.
            $this->assertSame(4, $rate->counted);
            $this->assertSame(1, $rate->excused);
            $this->assertSame(100.0, $rate->percentage());
        });
    }

    #[Test]
    public function unmarked_sessions_are_reported_rather_than_guessed_at(): void
    {
        $this->inTenant(function (): void {
            [$batch, $enrollment, $sessions] = $this->fiveHeldSessions();
            $statuses = $this->statuses();

            $this->mark($enrollment, $sessions[0], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[1], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[2], $statuses['PRESENT']);
            // Two sessions never marked.

            $rate = $this->rateFor($enrollment, $batch);

            // 100% of what is known, with the gap stated. Neither 100% nor 60% would be honest on
            // its own, so the choice is handed to whoever reads it.
            $this->assertSame(100.0, $rate->percentage());
            $this->assertSame(2, $rate->unmarked);
            $this->assertFalse($rate->isComplete());
        });
    }

    #[Test]
    public function a_learner_is_not_measured_against_sessions_before_they_joined(): void
    {
        $this->inTenant(function (): void {
            [$batch, $enrollment, $sessions] = $this->fiveHeldSessions();
            $statuses = $this->statuses();

            // Joined before the fourth session.
            $enrollment->update(['enrolled_on' => $sessions[3]->session_local_date->toDateString()]);

            $this->mark($enrollment, $sessions[3], $statuses['PRESENT']);
            $this->mark($enrollment, $sessions[4], $statuses['PRESENT']);

            $rate = $this->rateFor($enrollment->refresh(), $batch);

            $this->assertSame(2, $rate->counted);
            $this->assertSame(0, $rate->unmarked, 'Sessions before enrolment are not their gap to close.');
            $this->assertSame(100.0, $rate->percentage());
        });
    }

    #[Test]
    public function cancelled_sessions_leave_the_denominator(): void
    {
        $this->inTenant(function (): void {
            [$batch, $enrollment, $sessions] = $this->fiveHeldSessions();
            $statuses = $this->statuses();

            $sessions[4]->update(['status' => SessionStatus::Cancelled, 'cancel_reason' => 'Teacher illness']);

            foreach (array_slice($sessions, 0, 4) as $session) {
                $this->mark($enrollment, $session, $statuses['PRESENT']);
            }

            $rate = $this->rateFor($enrollment, $batch);

            // A class that did not happen was never an opportunity to miss.
            $this->assertSame(4, $rate->counted);
            $this->assertSame(0, $rate->unmarked);
        });
    }

    #[Test]
    public function uncounted_session_types_are_recorded_but_excluded(): void
    {
        $this->inTenant(function (): void {
            [$batch, $enrollment, $sessions] = $this->fiveHeldSessions();
            $statuses = $this->statuses();
            $classType = $sessions[0]->session_type_id;

            $makeUp = SessionType::factory()->makeUp()->create();
            $sessions[4]->update(['session_type_id' => $makeUp->getKey()]);

            AttendancePolicy::query()->create([
                'scope_type' => 'batch',
                'scope_id' => $batch->getKey(),
                'counted_session_type_ids' => [$classType],
            ]);
            app(AttendancePolicyResolver::class)->forget();

            foreach ($sessions as $session) {
                $this->mark($enrollment, $session, $statuses['PRESENT']);
            }

            // A make-up class must not inflate the headline figure.
            $this->assertSame(4, $this->rateFor($enrollment, $batch)->counted);
        });
    }

    #[Test]
    public function a_batch_where_attendance_is_informational_raises_no_risk_flag(): void
    {
        $this->inTenant(function (): void {
            [$batch, $enrollment, $sessions] = $this->fiveHeldSessions();
            $statuses = $this->statuses();

            foreach ($sessions as $session) {
                $this->mark($enrollment, $session, $statuses['ABSENT']);
            }

            $this->assertTrue($this->rateFor($enrollment, $batch)->isBelow(75));

            AttendancePolicy::query()->create([
                'scope_type' => 'batch', 'scope_id' => $batch->getKey(), 'is_compulsory' => false,
            ]);
            app(AttendancePolicyResolver::class)->forget();

            $rate = $this->rateFor($enrollment, $batch);

            $this->assertSame(0.0, $rate->percentage(), 'The figure is still shown.');
            $this->assertFalse($rate->isBelow(75), 'But it penalises nobody.');
            $this->assertTrue($rate->toArray()['is_informational']);
        });
    }

    #[Test]
    public function a_batch_override_beats_a_course_policy_and_says_so(): void
    {
        $this->inTenant(function (): void {
            [$batch] = $this->fiveHeldSessions();

            AttendancePolicy::query()->create([
                'scope_type' => 'course', 'scope_id' => $batch->course_id, 'late_grace_min' => 5,
            ]);
            AttendancePolicy::query()->create([
                'scope_type' => 'batch', 'scope_id' => $batch->getKey(), 'late_grace_min' => 15,
            ]);

            app(AttendancePolicyResolver::class)->forget();
            $policy = app(AttendancePolicyResolver::class)->for($batch);

            $this->assertSame(15, $policy->lateGraceMinutes);
            $this->assertSame('batch', $policy->originOf('late_grace_min'));
            $this->assertSame('course', $policy->originOf('is_compulsory'));
        });
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @param Closure(): void $callback */
    private function inTenant(Closure $callback): void
    {
        app(TenantContext::class)->runAs($this->tenant, $callback);
    }

    /** @return array{0: Batch, 1: Enrollment, 2: array<int, ClassSession>} */
    private function fiveHeldSessions(): array
    {
        LearnerStatus::query()->firstOrCreate(['code' => 'ACTIVE'], ['name' => 'Active', 'sort' => 0]);
        $enrolStatus = EnrollmentStatus::query()->firstOrCreate(
            ['code' => 'ACTIVE'],
            ['name' => 'Active', 'is_active_for_billing' => true, 'sort' => 0],
        );

        $brand = Brand::factory()->create();
        $branch = Branch::factory()->create(['brand_id' => $brand->getKey(), 'timezone' => 'Asia/Kathmandu']);
        $course = Course::factory()->create(['brand_id' => $brand->getKey()]);

        $batch = Batch::factory()->create([
            'course_id' => $course->getKey(),
            'branch_id' => $branch->getKey(),
            'timezone' => 'Asia/Kathmandu',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
        ]);

        TimetableSlot::query()->create([
            'batch_id' => $batch->getKey(),
            'session_type_id' => SessionType::factory()->create(['code' => 'CLASS'])->getKey(),
            'weekday' => Weekday::Saturday,
            'start_time_local' => '10:00:00',
            'duration_min' => 120,
        ]);

        app(SessionGenerator::class)->generate(
            $batch,
            CarbonImmutable::parse('2026-08-01', 'Asia/Kathmandu'),
            CarbonImmutable::parse('2026-08-31', 'Asia/Kathmandu'),
        );

        ClassSession::query()->update(['status' => SessionStatus::Held]);

        $learner = Learner::factory()->create([
            'status_id' => LearnerStatus::query()->where('code', 'ACTIVE')->first()->getKey(),
        ]);

        $enrollment = Enrollment::factory()->create([
            'learner_id' => $learner->getKey(),
            'batch_id' => $batch->getKey(),
            'status_id' => $enrolStatus->getKey(),
            'enrolled_on' => '2026-08-01',
        ]);

        $sessions = ClassSession::query()->orderBy('session_local_date')->get()->all();

        return [$batch->load('course'), $enrollment->setRelation('batch', $batch), $sessions];
    }

    /** @return array<string, AttendanceStatus> */
    private function statuses(): array
    {
        return [
            'PRESENT' => AttendanceStatus::factory()->present()->create(),
            'LATE' => AttendanceStatus::factory()->late()->create(),
            'ABSENT' => AttendanceStatus::factory()->absent()->create(),
            'EXCUSED' => AttendanceStatus::factory()->excused()->create(),
        ];
    }

    private function mark(Enrollment $enrollment, ClassSession $session, AttendanceStatus $status, ?int $minutesLate = null): void
    {
        AttendanceRecord::query()->create([
            'class_session_id' => $session->getKey(),
            'enrollment_id' => $enrollment->getKey(),
            'status_id' => $status->getKey(),
            'minutes_late' => $minutesLate,
            'recorded_at' => now(),
        ]);
    }

    private function rateFor(Enrollment $enrollment, Batch $batch): AttendanceRate
    {
        $period = app(PeriodService::class)->forLabel($batch, '2026-08');

        return app(AttendanceCalculator::class)->forPeriod($enrollment->setRelation('batch', $batch), $period);
    }
}
