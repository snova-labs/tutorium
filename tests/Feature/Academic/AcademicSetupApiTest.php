<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AssessmentType;
use App\Models\Batch;
use App\Models\Brand;
use App\Models\Course;
use App\Models\GradingScheme;
use App\Models\LearnerStatus;
use App\Models\SessionType;
use App\Models\Tenant;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Setting up teaching from the staff client: courses, classes, timetables, teachers, sessions. */
final class AcademicSetupApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->academy('One Academy', 'owner@one.test');
        $this->other = $this->academy('Other Academy', 'owner@other.test');

        Sanctum::actingAs($this->owner($this->tenant), ['staff']);
    }

    #[Test]
    public function an_owner_sets_up_a_class_from_nothing(): void
    {
        $brand = $this->in($this->tenant, fn () => Brand::query()->firstOrFail());
        $branch = $this->getJson('/api/v1/branches')->assertOk()->json('data.0.id');

        $course = $this->postJson('/api/v1/courses', [
            'brand_id' => $brand->id, 'name' => 'Mathematics — Grade 8', 'code' => 'MATH-8', 'period_type' => 'monthly',
        ])->assertCreated()->json('data.id');

        $batch = $this->postJson('/api/v1/batches', [
            'course_id' => $course, 'branch_id' => $branch, 'name' => 'Grade 8 · Morning', 'code' => 'G8-AM',
            'starts_on' => now()->startOfMonth()->toDateString(), 'ends_on' => now()->addMonths(2)->endOfMonth()->toDateString(),
        ])->assertCreated()->json('data.id');

        $types = $this->getJson('/api/v1/session-types')->assertOk()->json('data');
        $this->assertContains('CLASS', array_column($types, 'code'));

        $slot = $this->postJson("/api/v1/batches/{$batch}/timetable", [
            'session_type_id' => $types[0]['id'], 'weekday' => 2, 'start_time_local' => '07:00', 'duration_min' => 60,
        ])->assertCreated()->json('data.id');

        $staff = $this->getJson('/api/v1/staff')->assertOk()->json('data');
        $this->putJson("/api/v1/batches/{$batch}/teachers", ['teachers' => [['user_id' => $staff[0]['id']]]])->assertOk();

        $this->postJson("/api/v1/batches/{$batch}/sessions/generate", [
            'from' => now()->startOfMonth()->toDateString(), 'to' => now()->addMonth()->endOfMonth()->toDateString(),
        ])->assertOk()->assertJsonPath('data.created', fn ($n) => $n >= 8);

        $show = $this->getJson("/api/v1/batches/{$batch}")->assertOk();
        $this->assertCount(1, $show->json('data.timetable'));
        $this->assertCount(1, $show->json('data.teachers'));

        // A slot added by mistake can be taken off; the sessions already generated stay.
        $this->deleteJson("/api/v1/batches/{$batch}/timetable/{$slot}")->assertOk();
        $this->assertCount(0, $this->getJson("/api/v1/batches/{$batch}")->json('data.timetable'));
        $this->assertGreaterThan(0, $this->getJson("/api/v1/batches/{$batch}")->json('data.sessions_count'));
    }

    #[Test]
    public function the_staff_list_names_each_person_and_their_roles(): void
    {
        $this->getJson('/api/v1/staff')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'owner@one.test')
            ->assertJsonPath('data.0.roles.0', 'Owner');
    }

    #[Test]
    public function another_academys_records_cannot_be_used_in_this_one(): void
    {
        $batch = $this->in($this->tenant, function (): Batch {
            $course = Course::factory()->create();

            return Batch::factory()->create(['course_id' => $course->id]);
        });
        $theirBrand = $this->in($this->other, fn () => Brand::query()->firstOrFail());
        $theirType = $this->in($this->other, fn () => SessionType::query()->firstOrFail());
        $theirOwner = $this->owner($this->other);

        $this->postJson('/api/v1/courses', [
            'brand_id' => $theirBrand->id, 'name' => 'Borrowed brand', 'code' => 'BORROW', 'period_type' => 'monthly',
        ])->assertStatus(422)->assertJsonValidationErrors('brand_id');

        $this->postJson("/api/v1/batches/{$batch->id}/timetable", [
            'session_type_id' => $theirType->id, 'weekday' => 1, 'start_time_local' => '09:00', 'duration_min' => 60,
        ])->assertStatus(422)->assertJsonValidationErrors('session_type_id');

        $this->putJson("/api/v1/batches/{$batch->id}/teachers", ['teachers' => [['user_id' => $theirOwner->id]]])
            ->assertStatus(422)->assertJsonValidationErrors('teachers.0.user_id');

        $this->assertSame(0, $this->in($this->tenant, fn () => $batch->teachers()->count()));

        // The same holds for ids that would otherwise be stored as they came.
        $theirStatus = $this->in($this->other, fn () => LearnerStatus::query()->firstOrFail());
        $this->postJson('/api/v1/learners', ['legal_name' => 'Borrowed Status', 'status_id' => $theirStatus->id])
            ->assertStatus(422)->assertJsonValidationErrors('status_id');

        $theirType = $this->in($this->other, fn () => AssessmentType::query()->firstOrFail());
        $ourScheme = $this->in($this->tenant, fn () => GradingScheme::query()->where('code', 'POINTS')->firstOrFail());
        $this->postJson("/api/v1/batches/{$batch->id}/assessments", [
            'assessment_type_id' => $theirType->id, 'grading_scheme_id' => $ourScheme->id, 'title' => 'Borrowed type', 'max_points' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('assessment_type_id');
    }

    #[Test]
    public function a_slot_is_removed_only_through_its_own_class(): void
    {
        [$first, $slot] = $this->in($this->tenant, function (): array {
            $course = Course::factory()->create();
            $a = Batch::factory()->create(['course_id' => $course->id]);
            $b = Batch::factory()->create(['course_id' => $course->id]);
            $slot = TimetableSlot::query()->create([
                'batch_id' => $b->id, 'session_type_id' => SessionType::query()->value('id'),
                'weekday' => 3, 'start_time_local' => '10:00:00', 'duration_min' => 60,
            ]);

            return [$a, $slot];
        });

        $this->deleteJson("/api/v1/batches/{$first->id}/timetable/{$slot->id}")->assertNotFound();
        $this->assertNotNull($this->in($this->tenant, fn () => TimetableSlot::query()->find($slot->id)));
    }

    private function academy(string $name, string $email): Tenant
    {
        return app(TenantProvisioner::class)->provision([
            'name' => $name, 'owner_name' => 'Sample Owner', 'owner_email' => $email,
            'password' => 'a-long-enough-password', 'timezone' => 'Asia/Kathmandu', 'preset_code' => 'kids-tutoring-south-asia',
        ])['tenant'];
    }

    private function owner(Tenant $tenant): User
    {
        return $this->in($tenant, fn () => User::query()->firstOrFail());
    }

    /**
     * @template T
     *
     * @param Closure(): T $run
     * @return T
     */
    private function in(Tenant $tenant, Closure $run): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $run);
    }
}
