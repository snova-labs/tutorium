<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Enums\Weekday;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\SessionType;
use App\Models\Tenant;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SchedulingApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function generating_a_month_reports_what_it_created_and_skipped(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('sched-one');
        $batch = $this->batchWithTimetable($tenant);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/batches/{$batch->getKey()}/sessions/generate", [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ])->assertOk()
            ->assertJsonPath('data.created', 5)
            ->assertJsonPath('data.summary', '5 sessions created.');
    }

    #[Test]
    public function a_session_carries_local_utc_and_viewer_time(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('sched-two');
        $batch = $this->batchWithTimetable($tenant);

        app(TenantContext::class)->runAs($tenant, fn () => $owner->update(['timezone' => 'Europe/Berlin']));

        Sanctum::actingAs($owner->fresh());

        $this->postJson("/api/v1/batches/{$batch->getKey()}/sessions/generate", [
            'from' => '2026-08-01', 'to' => '2026-08-08',
        ])->assertOk();

        $response = $this->getJson("/api/v1/batches/{$batch->getKey()}/sessions")->assertOk();

        $first = $response->json('data.0');

        $this->assertSame('10:00', $first['local']['time']);
        $this->assertSame('Asia/Kathmandu', $first['local']['timezone']);
        $this->assertStringContainsString('T04:15', $first['utc']['starts_at']);
        $this->assertSame('Europe/Berlin', $first['viewer']['timezone']);
    }

    #[Test]
    public function cancelling_requires_a_reason_and_keeps_the_record(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('sched-three');
        $batch = $this->batchWithTimetable($tenant);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/batches/{$batch->getKey()}/sessions/generate", [
            'from' => '2026-08-01', 'to' => '2026-08-08',
        ]);

        $session = app(TenantContext::class)->runAs($tenant, fn () => ClassSession::query()->first());

        $this->postJson("/api/v1/sessions/{$session->getKey()}/cancel", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/sessions/{$session->getKey()}/cancel", ['reason' => 'Teacher illness'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.counts_toward_attendance', false);

        // The row survives — a cancelled class is part of the history, not an absence of one.
        app(TenantContext::class)->runAs($tenant, function () use ($session): void {
            $this->assertNotNull(ClassSession::query()->find($session->getKey()));
        });
    }

    #[Test]
    public function rescheduling_onto_a_missing_local_hour_is_refused(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('sched-four');

        $batch = app(TenantContext::class)->runAs($tenant, function (): Batch {
            $brand = Brand::factory()->create();
            $branch = Branch::factory()->create(['brand_id' => $brand->getKey(), 'timezone' => 'America/Toronto']);
            $course = Course::factory()->create(['brand_id' => $brand->getKey()]);

            return Batch::factory()->create([
                'course_id' => $course->getKey(),
                'branch_id' => $branch->getKey(),
                'timezone' => 'America/Toronto',
                'starts_on' => '2026-03-01',
                'ends_on' => '2026-03-31',
            ]);
        });

        $session = app(TenantContext::class)->runAs($tenant, fn () => ClassSession::factory()->create([
            'batch_id' => $batch->getKey(),
            'session_type_id' => SessionType::factory()->create()->getKey(),
        ]));

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/sessions/{$session->getKey()}/reschedule", [
            'session_local_date' => '2026-03-08',
            'start_time_local' => '02:30',
        ])->assertStatus(422)->assertJsonValidationErrors('starts_at');
    }

    #[Test]
    public function a_teacher_reaches_only_the_batches_they_teach(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('sched-five');
        $mine = $this->batchWithTimetable($tenant, 'MINE');
        $theirs = $this->batchWithTimetable($tenant, 'THEIRS');

        $teacher = app(TenantContext::class)->runAs($tenant, function () use ($mine): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles(['Teacher']);
            $mine->teachers()->attach($user->getKey(), ['tenant_id' => $mine->tenant_id, 'role' => 'lead']);

            return $user->fresh();
        });

        Sanctum::actingAs($teacher);

        $this->getJson('/api/v1/batches')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/batches/{$mine->getKey()}")->assertOk();
        $this->getJson("/api/v1/batches/{$theirs->getKey()}")->assertForbidden();

        // Teaching a batch does not include rescheduling it.
        $this->postJson("/api/v1/batches/{$mine->getKey()}/sessions/generate", [
            'from' => '2026-08-01', 'to' => '2026-08-08',
        ])->assertForbidden();
    }

    #[Test]
    public function changing_the_timezone_after_sessions_exist_is_refused(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('sched-six');
        $batch = $this->batchWithTimetable($tenant);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/batches/{$batch->getKey()}/sessions/generate", [
            'from' => '2026-08-01', 'to' => '2026-08-08',
        ])->assertOk();

        $this->patchJson("/api/v1/batches/{$batch->getKey()}", ['timezone' => 'America/Toronto'])
            ->assertStatus(422)->assertJsonValidationErrors('timezone');
    }

    #[Test]
    public function an_invalid_timezone_is_rejected_at_the_edge(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('sched-seven');

        [$course, $branch] = app(TenantContext::class)->runAs($tenant, function (): array {
            $brand = Brand::factory()->create();

            return [
                Course::factory()->create(['brand_id' => $brand->getKey()]),
                Branch::factory()->create(['brand_id' => $brand->getKey()]),
            ];
        });

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/batches', [
            'course_id' => $course->getKey(),
            'branch_id' => $branch->getKey(),
            'name' => 'Typo batch',
            'code' => 'TYPO',
            'timezone' => 'Asia/Katmandu', // one 'h' short
            'starts_on' => '2026-08-01',
        ])->assertStatus(422)->assertJsonValidationErrors('timezone');
    }

    /** @return array{0: Tenant, 1: User} */
    private function tenantWithOwner(string $slug): array
    {
        $context = app(TenantContext::class);

        $tenant = $context->withoutScoping(fn () => Tenant::factory()->create([
            'slug' => $slug, 'status' => Tenant::STATUS_ACTIVE,
        ]));

        app(RolesAndPermissionsSeeder::class)->run($tenant);

        $owner = $context->runAs($tenant, function (): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles(['Owner']);

            return $user->fresh();
        });

        return [$tenant, $owner];
    }

    private function batchWithTimetable(Tenant $tenant, string $code = 'BATCH'): Batch
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($code): Batch {
            $brand = Brand::factory()->create();
            $branch = Branch::factory()->create(['brand_id' => $brand->getKey(), 'timezone' => 'Asia/Kathmandu']);
            $course = Course::factory()->create(['brand_id' => $brand->getKey()]);

            $batch = Batch::factory()->create([
                'course_id' => $course->getKey(),
                'branch_id' => $branch->getKey(),
                'code' => $code,
                'timezone' => 'Asia/Kathmandu',
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-12-31',
            ]);

            TimetableSlot::query()->create([
                'batch_id' => $batch->getKey(),
                'session_type_id' => SessionType::factory()->create()->getKey(),
                'weekday' => Weekday::Saturday,
                'start_time_local' => '10:00:00',
                'duration_min' => 120,
            ]);

            return $batch;
        });
    }
}
