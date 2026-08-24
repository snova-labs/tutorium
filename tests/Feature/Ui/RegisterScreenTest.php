<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Enums\Weekday;
use App\Livewire\Attendance\Register;
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
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Attendance\AttendancePolicyResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The screen that decides whether the product gets used.
 */
final class RegisterScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClassSession $session;

    private User $teacher;

    /** @var array<string, AttendanceStatus> */
    private array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
        ])['tenant'];

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->statuses = AttendanceStatus::query()->get()->keyBy('code')->all();
            $this->buildSession();
            $this->buildTeacher();
        });

        $this->actingAs($this->teacher);
        app(TenantContext::class)->set($this->tenant);
    }

    #[Test]
    public function the_policy_in_force_is_shown_above_the_register(): void
    {
        Livewire::test(Register::class, ['session' => $this->session])
            ->assertSee('count as attended')
            // The origin chip: a teacher can see whether the grace period is this
            // class's decision or the whole academy's.
            ->assertSeeHtml('tenant');
    }

    #[Test]
    public function tapping_a_mark_twice_clears_it(): void
    {
        $enrollmentId = $this->firstEnrollmentId();

        Livewire::test(Register::class, ['session' => $this->session])
            ->call('mark', $enrollmentId, $this->statuses['PRESENT']->id)
            ->assertSet("marks.{$enrollmentId}", $this->statuses['PRESENT']->id)
            // One tap to undo a mis-tap, rather than a trip to a menu.
            ->call('mark', $enrollmentId, $this->statuses['PRESENT']->id)
            ->assertSet("marks.{$enrollmentId}", null);
    }

    #[Test]
    public function marking_the_rest_does_not_overwrite_a_deliberate_absence(): void
    {
        $ids = $this->enrollmentIds();

        Livewire::test(Register::class, ['session' => $this->session])
            ->call('mark', $ids[0], $this->statuses['ABSENT']->id)
            ->call('markRemaining', $this->statuses['PRESENT']->id)
            ->assertSet("marks.{$ids[0]}", $this->statuses['ABSENT']->id)
            ->assertSet("marks.{$ids[1]}", $this->statuses['PRESENT']->id);
    }

    #[Test]
    public function the_running_tally_uses_the_same_rules_the_saved_figure_will(): void
    {
        $ids = $this->enrollmentIds();

        $component = Livewire::test(Register::class, ['session' => $this->session])
            ->call('mark', $ids[0], $this->statuses['PRESENT']->id)
            ->call('mark', $ids[1], $this->statuses['LATE']->id)
            ->call('mark', $ids[2], $this->statuses['EXCUSED']->id);

        $tally = $component->get('tally');

        // Late counts as attended under this policy; excused leaves the
        // denominator rather than scoring zero.
        $this->assertSame(2, $tally['attended']);
        $this->assertSame(2, $tally['counted']);
        $this->assertSame(1, $tally['excused']);
        $this->assertSame(100.0, $tally['percentage']);
    }

    #[Test]
    public function the_tally_changes_when_the_policy_does_without_any_mark_changing(): void
    {
        $ids = $this->enrollmentIds();

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            AttendancePolicy::query()->create([
                'scope_type' => 'batch',
                'scope_id' => $this->session->batch_id,
                'allow_late_join' => false,
            ]);
        });

        app(AttendancePolicyResolver::class)->forget();

        $tally = Livewire::test(Register::class, ['session' => $this->session])
            ->call('mark', $ids[0], $this->statuses['PRESENT']->id)
            ->call('mark', $ids[1], $this->statuses['LATE']->id)
            ->get('tally');

        // The same two marks, read differently.
        $this->assertSame(1, $tally['attended']);
        $this->assertSame(50.0, $tally['percentage']);
    }

    #[Test]
    public function saving_records_every_mark_in_one_action(): void
    {
        $ids = $this->enrollmentIds();

        Livewire::test(Register::class, ['session' => $this->session])
            ->call('mark', $ids[0], $this->statuses['PRESENT']->id)
            ->call('mark', $ids[1], $this->statuses['ABSENT']->id)
            ->set("notes.{$ids[1]}", 'Called in sick')
            ->call('save')
            ->assertSet('saved', true)
            ->assertSet('error', null);

        app(TenantContext::class)->runAs($this->tenant, function () use ($ids): void {
            $this->assertSame(2, AttendanceRecord::query()
                ->where('class_session_id', $this->session->getKey())->count());

            $this->assertSame('Called in sick', AttendanceRecord::query()
                ->where('enrollment_id', $ids[1])->value('note'));
        });
    }

    #[Test]
    public function reopening_a_saved_register_shows_what_was_recorded(): void
    {
        $ids = $this->enrollmentIds();

        Livewire::test(Register::class, ['session' => $this->session])
            ->call('mark', $ids[0], $this->statuses['ABSENT']->id)
            ->call('save');

        Livewire::test(Register::class, ['session' => $this->session])
            ->assertSet("marks.{$ids[0]}", $this->statuses['ABSENT']->id);
    }

    #[Test]
    public function saving_nothing_says_so_rather_than_appearing_to_work(): void
    {
        Livewire::test(Register::class, ['session' => $this->session])
            ->call('save')
            ->assertSet('saved', false)
            ->assertSet('error', 'Nothing to save yet.');
    }

    #[Test]
    public function a_teacher_cannot_open_a_register_for_a_class_they_do_not_teach(): void
    {
        $other = app(TenantContext::class)->runAs($this->tenant, function (): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles(['Teacher']);

            return $user->fresh();
        });

        $this->actingAs($other);

        Livewire::test(Register::class, ['session' => $this->session])->assertForbidden();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function buildSession(): void
    {
        $brand = Brand::query()->first();
        $branch = Branch::query()->first();
        $course = Course::factory()->create(['brand_id' => $brand->getKey()]);

        $batch = Batch::factory()->create([
            'course_id' => $course->getKey(),
            'branch_id' => $branch->getKey(),
            'timezone' => 'Asia/Kathmandu',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
            'capacity' => null,
        ]);

        TimetableSlot::query()->create([
            'batch_id' => $batch->getKey(),
            'session_type_id' => SessionType::query()->where('code', 'CLASS')->first()->getKey(),
            'weekday' => Weekday::Saturday,
            'start_time_local' => '10:00:00',
            'duration_min' => 120,
        ]);

        app(SessionGenerator::class)->generate(
            $batch,
            CarbonImmutable::parse('2026-08-01', 'Asia/Kathmandu'),
            CarbonImmutable::parse('2026-08-08', 'Asia/Kathmandu'),
        );

        $status = EnrollmentStatus::query()->where('code', 'ACTIVE')->first();
        $learnerStatus = LearnerStatus::query()->where('code', 'ACTIVE')->first();

        foreach (range(1, 3) as $i) {
            $learner = Learner::factory()->create(['status_id' => $learnerStatus->getKey()]);

            Enrollment::factory()->create([
                'learner_id' => $learner->getKey(),
                'batch_id' => $batch->getKey(),
                'status_id' => $status->getKey(),
                'enrolled_on' => '2026-08-01',
            ]);
        }

        $this->session = ClassSession::query()->orderBy('session_local_date')->firstOrFail();
    }

    private function buildTeacher(): void
    {
        $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
        $user->syncRoles(['Teacher']);

        $this->session->batch->teachers()->attach($user->getKey(), [
            'tenant_id' => $this->tenant->getKey(), 'role' => 'lead',
        ]);

        $this->teacher = $user->fresh();
    }

    /** @return array<int, int> */
    private function enrollmentIds(): array
    {
        return app(TenantContext::class)->runAs(
            $this->tenant,
            fn () => Enrollment::query()->where('batch_id', $this->session->batch_id)
                ->orderBy('id')->pluck('id')->all(),
        );
    }

    private function firstEnrollmentId(): int
    {
        return $this->enrollmentIds()[0];
    }
}
