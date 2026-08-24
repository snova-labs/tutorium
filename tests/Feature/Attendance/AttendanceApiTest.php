<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Enums\SessionStatus;
use App\Enums\Weekday;
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
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\SessionGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_roster_arrives_in_one_request_with_the_policy_attached(): void
    {
        [$tenant, $teacher, $session] = $this->scenario('att-one');
        $this->statusPair($tenant);

        Sanctum::actingAs($teacher);

        $response = $this->getJson("/api/v1/sessions/{$session->getKey()}/attendance")->assertOk();

        // One call, small payload: this screen opens on a phone with a class waiting.
        $response->assertJsonPath('data.policy.allow_late_join', true)
            ->assertJsonPath('data.session.local.timezone', 'Asia/Kathmandu')
            ->assertJsonCount(4, 'data.statuses')
            ->assertJsonCount(3, 'data.roster');

        $this->assertFalse($response->json('data.roster.0.marked'));
    }

    #[Test]
    public function saving_twice_updates_rather_than_duplicating(): void
    {
        [$tenant, $teacher, $session] = $this->scenario('att-two');
        [$present, $absent] = $this->statusPair($tenant);
        $roster = $this->rosterIds($tenant, $session);

        Sanctum::actingAs($teacher);

        $marks = collect($roster)->map(fn (int $id) => [
            'enrollment_id' => $id, 'status_id' => $present->getKey(),
        ])->all();

        $this->putJson("/api/v1/sessions/{$session->getKey()}/attendance", ['marks' => $marks])
            ->assertOk()->assertJsonPath('data.saved', 3)->assertJsonPath('data.updated', 0);

        // A teacher correcting one mark and pressing save again is normal, not an error.
        $marks[0]['status_id'] = $absent->getKey();

        $this->putJson("/api/v1/sessions/{$session->getKey()}/attendance", ['marks' => $marks])
            ->assertOk()->assertJsonPath('data.saved', 0)->assertJsonPath('data.updated', 3);

        app(TenantContext::class)->runAs($tenant, function () use ($session): void {
            $this->assertSame(3, AttendanceRecord::query()
                ->where('class_session_id', $session->getKey())->count());
        });
    }

    #[Test]
    public function marking_a_register_marks_the_session_as_held(): void
    {
        [$tenant, $teacher, $session] = $this->scenario('att-three');
        [$present] = $this->statusPair($tenant);
        $roster = $this->rosterIds($tenant, $session);

        $this->assertSame(SessionStatus::Scheduled, $session->status);

        Sanctum::actingAs($teacher);

        $this->putJson("/api/v1/sessions/{$session->getKey()}/attendance", [
            'marks' => [['enrollment_id' => $roster[0], 'status_id' => $present->getKey()]],
        ])->assertOk();

        // Asking a teacher to mark the session held separately would guarantee it is sometimes
        // forgotten, and every figure downstream depends on it.
        app(TenantContext::class)->runAs($tenant, function () use ($session): void {
            $this->assertSame(SessionStatus::Held, ClassSession::query()->find($session->getKey())->status);
        });
    }

    #[Test]
    public function the_remaining_action_only_touches_unmarked_learners(): void
    {
        [$tenant, $teacher, $session] = $this->scenario('att-four');
        [$present, $absent] = $this->statusPair($tenant);
        $roster = $this->rosterIds($tenant, $session);

        Sanctum::actingAs($teacher);

        $this->putJson("/api/v1/sessions/{$session->getKey()}/attendance", [
            'marks' => [['enrollment_id' => $roster[0], 'status_id' => $absent->getKey()]],
        ])->assertOk();

        $this->postJson("/api/v1/sessions/{$session->getKey()}/attendance/remaining", [
            'status_id' => $present->getKey(),
        ])->assertOk()->assertJsonPath('data.saved', 2);

        // The deliberate absent mark survives the bulk action.
        app(TenantContext::class)->runAs($tenant, function () use ($session, $roster, $absent): void {
            $record = AttendanceRecord::query()
                ->where('class_session_id', $session->getKey())
                ->where('enrollment_id', $roster[0])->first();

            $this->assertSame($absent->getKey(), $record->status_id);
        });
    }

    #[Test]
    public function a_teacher_cannot_mark_a_register_for_a_batch_they_do_not_teach(): void
    {
        [$tenant, , $session] = $this->scenario('att-five');

        $other = app(TenantContext::class)->runAs($tenant, function (): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles(['Teacher']);

            return $user->fresh();
        });

        Sanctum::actingAs($other);

        $this->getJson("/api/v1/sessions/{$session->getKey()}/attendance")->assertForbidden();
    }

    #[Test]
    public function front_desk_can_read_a_register_but_not_write_one(): void
    {
        [$tenant, , $session] = $this->scenario('att-six');
        [$present] = $this->statusPair($tenant);
        $roster = $this->rosterIds($tenant, $session);

        $frontDesk = app(TenantContext::class)->runAs($tenant, function (): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles(['Front desk']);

            return $user->fresh();
        });

        Sanctum::actingAs($frontDesk);

        $this->getJson("/api/v1/sessions/{$session->getKey()}/attendance")->assertOk();

        $this->putJson("/api/v1/sessions/{$session->getKey()}/attendance", [
            'marks' => [['enrollment_id' => $roster[0], 'status_id' => $present->getKey()]],
        ])->assertForbidden();
    }

    #[Test]
    public function a_cancelled_session_has_no_register(): void
    {
        [$tenant, $teacher, $session] = $this->scenario('att-seven');
        [$present] = $this->statusPair($tenant);
        $roster = $this->rosterIds($tenant, $session);

        app(TenantContext::class)->runAs($tenant, fn () => $session->update([
            'status' => SessionStatus::Cancelled, 'cancel_reason' => 'Teacher illness',
        ]));

        Sanctum::actingAs($teacher);

        $this->putJson("/api/v1/sessions/{$session->getKey()}/attendance", [
            'marks' => [['enrollment_id' => $roster[0], 'status_id' => $present->getKey()]],
        ])->assertStatus(422)->assertJsonValidationErrors('session');
    }

    #[Test]
    public function the_batch_summary_reports_gaps_alongside_the_percentage(): void
    {
        [$tenant, $teacher, $session] = $this->scenario('att-eight');
        [$present] = $this->statusPair($tenant);
        $roster = $this->rosterIds($tenant, $session);
        $batchId = $session->batch_id;

        Sanctum::actingAs($teacher);

        $this->putJson("/api/v1/sessions/{$session->getKey()}/attendance", [
            'marks' => [['enrollment_id' => $roster[0], 'status_id' => $present->getKey()]],
        ])->assertOk();

        $response = $this->getJson("/api/v1/batches/{$batchId}/attendance?period=2026-08")->assertOk();

        $this->assertSame(100.0, $response->json('data.learners.0.attendance.percentage'));
        // Two learners never marked for a held session: stated, not folded into anyone's figure.
        $this->assertSame(2, $response->json('data.unmarked_sessions'));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @return array{0: Tenant, 1: User, 2: ClassSession} */
    private function scenario(string $slug): array
    {
        $context = app(TenantContext::class);

        $tenant = $context->withoutScoping(fn () => Tenant::factory()->create([
            'slug' => $slug, 'status' => Tenant::STATUS_ACTIVE,
        ]));

        app(RolesAndPermissionsSeeder::class)->run($tenant);

        $session = $context->runAs($tenant, function (): ClassSession {
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
                CarbonImmutable::parse('2026-08-08', 'Asia/Kathmandu'),
            );

            foreach (range(1, 3) as $i) {
                $learner = Learner::factory()->create([
                    'status_id' => LearnerStatus::query()->where('code', 'ACTIVE')->first()->getKey(),
                ]);
                Enrollment::factory()->create([
                    'learner_id' => $learner->getKey(),
                    'batch_id' => $batch->getKey(),
                    'status_id' => $enrolStatus->getKey(),
                    'enrolled_on' => '2026-08-01',
                ]);
            }

            return ClassSession::query()->orderBy('session_local_date')->firstOrFail();
        });

        $teacher = $context->runAs($tenant, function () use ($session): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles(['Teacher']);
            $session->batch->teachers()->attach($user->getKey(), [
                'tenant_id' => $session->tenant_id, 'role' => 'lead',
            ]);

            return $user->fresh();
        });

        return [$tenant, $teacher, $session];
    }

    /**
     * Present and Absent, plus the Late and Excused rows the roster endpoint expects to find.
     *
     * @return array{0: AttendanceStatus, 1: AttendanceStatus}
     */
    private function statusPair(Tenant $tenant): array
    {
        return app(TenantContext::class)->runAs($tenant, function (): array {
            $present = AttendanceStatus::factory()->present()->create();
            AttendanceStatus::factory()->late()->create();
            $absent = AttendanceStatus::factory()->absent()->create();
            AttendanceStatus::factory()->excused()->create();

            return [$present, $absent];
        });
    }

    /** @return array<int, int> */
    private function rosterIds(Tenant $tenant, ClassSession $session): array
    {
        return app(TenantContext::class)->runAs($tenant, fn () => Enrollment::query()
            ->where('batch_id', $session->batch_id)->orderBy('id')->pluck('id')->all());
    }
}
