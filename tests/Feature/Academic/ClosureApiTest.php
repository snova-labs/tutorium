<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Enums\SessionStatus;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Holiday;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Closure as PhpClosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The days a location is closed.
 *
 * Generation skipping a closure is only half of it: a closure added after the sessions were
 * generated has to be able to clear them, or the calendar says the academy is teaching on a day
 * everybody knows it is shut.
 */
final class ClosureApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->academy('Closure Academy', 'owner@closure.test');
        Sanctum::actingAs($this->owner($this->tenant), ['staff']);

        $this->branch = (int) $this->in($this->tenant, fn () => Branch::query()->firstOrFail()->getKey());
    }

    #[Test]
    public function a_closure_is_added_and_only_the_days_ahead_are_listed(): void
    {
        $past = now()->subMonth()->toDateString();
        $soon = now()->addWeeks(2)->toDateString();

        $this->postJson("/api/v1/branches/{$this->branch}/closures", ['date' => $past, 'name' => 'Last year'])
            ->assertCreated();
        $this->postJson("/api/v1/branches/{$this->branch}/closures", ['date' => $soon, 'name' => 'Founders Day'])
            ->assertCreated()
            ->assertJsonPath('data.blocks_sessions', true)
            ->assertJsonPath('meta.sessions_on_the_day', 0);

        // The default list is what still changes anything: dates from today on.
        $this->getJson("/api/v1/branches/{$this->branch}/closures")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Founders Day');

        // A stretch of dates is asked for explicitly, and then the past is included.
        $this->getJson("/api/v1/branches/{$this->branch}/closures?from={$past}&to={$soon}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function the_same_date_cannot_be_closed_twice(): void
    {
        $date = now()->addWeek()->toDateString();

        $this->postJson("/api/v1/branches/{$this->branch}/closures", ['date' => $date, 'name' => 'Holiday'])
            ->assertCreated();

        $this->postJson("/api/v1/branches/{$this->branch}/closures", ['date' => $date, 'name' => 'Same day again'])
            ->assertStatus(422)->assertJsonValidationErrors('date');
    }

    #[Test]
    public function sessions_already_on_the_calendar_are_reported_and_cancelled_when_asked(): void
    {
        [$date, $sessionId] = $this->sessionNextWeek();

        // Reported but left alone: the closure may be the office shutting, not the class.
        $this->postJson("/api/v1/branches/{$this->branch}/closures", ['date' => $date, 'name' => 'Snow day'])
            ->assertCreated()
            ->assertJsonPath('meta.sessions_on_the_day', 1)
            ->assertJsonPath('meta.sessions_cancelled', 0);

        $this->assertSame(
            SessionStatus::Scheduled,
            $this->in($this->tenant, fn () => ClassSession::query()->findOrFail($sessionId)->status),
        );

        // Asked for on another date, the sessions go, with the closure's name as the reason.
        [$other, $otherSession] = $this->sessionNextWeek(14);

        $this->postJson("/api/v1/branches/{$this->branch}/closures", [
            'date' => $other, 'name' => 'Public holiday', 'cancel_sessions' => true,
        ])->assertCreated()->assertJsonPath('meta.sessions_cancelled', 1);

        $cancelled = $this->in($this->tenant, fn () => ClassSession::query()->findOrFail($otherSession));

        $this->assertSame(SessionStatus::Cancelled, $cancelled->status);
        $this->assertSame('Closed: Public holiday', $cancelled->cancel_reason);
    }

    #[Test]
    public function generation_skips_a_closed_day_and_says_so(): void
    {
        $batch = $this->batch();
        $weekday = now()->addWeek()->startOfWeek()->addDay();
        $closed = $weekday->toDateString();

        $this->postJson("/api/v1/branches/{$this->branch}/closures", ['date' => $closed, 'name' => 'Closed for works'])
            ->assertCreated();

        $types = $this->getJson('/api/v1/session-types')->json('data');
        $this->postJson("/api/v1/batches/{$batch}/timetable", [
            'session_type_id' => $types[0]['id'], 'weekday' => $weekday->isoWeekday(),
            'start_time_local' => '09:00', 'duration_min' => 60,
        ])->assertCreated();

        $generated = $this->postJson("/api/v1/batches/{$batch}/sessions/generate", [
            'from' => $weekday->toDateString(), 'to' => $weekday->addWeek()->toDateString(),
        ])->assertOk()->json('data');

        $this->assertSame(0, $this->in($this->tenant, fn () => ClassSession::query()
            ->where('batch_id', $batch)->whereDate('session_local_date', $closed)->count()));

        // The skip is reported rather than silent: nobody should wonder where a session went.
        $this->assertNotEmpty($generated['skipped'] ?? []);
    }

    #[Test]
    public function a_closure_is_removed_but_the_cancellations_stand(): void
    {
        [$date, $sessionId] = $this->sessionNextWeek();

        $id = $this->postJson("/api/v1/branches/{$this->branch}/closures", [
            'date' => $date, 'name' => 'Called off', 'cancel_sessions' => true,
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/closures/{$id}")->assertOk();

        $this->assertNull($this->in($this->tenant, fn () => Holiday::query()->find($id)));
        $this->assertSame(
            SessionStatus::Cancelled,
            $this->in($this->tenant, fn () => ClassSession::query()->findOrFail($sessionId)->status),
        );
    }

    #[Test]
    public function another_academys_location_and_closure_are_out_of_reach(): void
    {
        $other = $this->academy('Other Academy', 'owner@other-closure.test');
        $theirBranch = $this->in($other, fn () => Branch::query()->firstOrFail());
        $theirClosure = $this->in($other, fn () => Holiday::query()->create([
            'branch_id' => $theirBranch->getKey(), 'date' => now()->addWeek()->toDateString(), 'name' => 'Theirs',
        ]));

        // Not-found, never forbidden: a 403 would confirm the record exists somewhere.
        $this->getJson("/api/v1/branches/{$theirBranch->getKey()}/closures")->assertNotFound();
        $this->postJson("/api/v1/branches/{$theirBranch->getKey()}/closures", [
            'date' => now()->addWeek()->toDateString(), 'name' => 'Mine on their branch',
        ])->assertNotFound();
        $this->deleteJson("/api/v1/closures/{$theirClosure->getKey()}")->assertNotFound();

        $this->assertNotNull($this->in($other, fn () => Holiday::query()->find($theirClosure->getKey())));
    }

    #[Test]
    public function front_desk_may_not_close_a_location(): void
    {
        $frontDesk = $this->in($this->tenant, function (): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles(['Front desk']);

            return $user->fresh();
        });

        Sanctum::actingAs($frontDesk, ['staff']);

        $this->postJson("/api/v1/branches/{$this->branch}/closures", [
            'date' => now()->addWeek()->toDateString(), 'name' => 'Not mine to set',
        ])->assertForbidden();

        $this->assertSame(0, $this->in($this->tenant, fn () => Holiday::query()->count()));
    }

    /**
     * A class with one session next week, and the local date that session falls on.
     *
     * @return array{0: string, 1: int}
     */
    private function sessionNextWeek(int $days = 7): array
    {
        $date = now()->addDays($days)->toDateString();
        $batch = $this->batch();
        $type = (int) $this->getJson('/api/v1/session-types')->json('data.0.id');

        $session = $this->in($this->tenant, fn () => ClassSession::query()->create([
            'batch_id' => $batch,
            'session_type_id' => $type,
            'session_local_date' => $date,
            'start_time_local' => '09:00:00',
            'starts_at_utc' => now()->addDays($days)->setTime(3, 15),
            'ends_at_utc' => now()->addDays($days)->setTime(4, 15),
            'status' => SessionStatus::Scheduled,
        ]));

        return [$date, (int) $session->getKey()];
    }

    private function batch(): int
    {
        return $this->in($this->tenant, function (): int {
            $course = Course::factory()->create();

            return (int) Batch::factory()->create([
                'course_id' => $course->getKey(),
                'branch_id' => $this->branch,
                'starts_on' => now()->subWeek()->toDateString(),
                'ends_on' => now()->addMonths(3)->toDateString(),
            ])->getKey();
        });
    }

    private function academy(string $name, string $email): Tenant
    {
        return app(TenantProvisioner::class)->provision([
            'name' => $name, 'owner_name' => 'Sample Owner', 'owner_email' => $email,
            'password' => 'a-long-enough-password', 'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
        ])['tenant'];
    }

    private function owner(Tenant $tenant): User
    {
        return $this->in($tenant, fn () => User::query()->firstOrFail());
    }

    /**
     * @template T
     *
     * @param PhpClosure(): T $run
     * @return T
     */
    private function in(Tenant $tenant, PhpClosure $run): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $run);
    }
}
