<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Enums\Weekday;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Holiday;
use App\Models\SessionType;
use App\Models\Tenant;
use App\Models\TimetableSlot;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Milestone M2's gate (SL-PLN-008 §5): "a DST-crossing batch generates correct sessions."
 *
 * These fixtures stay permanently. Timezone defects are invisible in a single-zone test suite and
 * expensive to find in production, where they show up as a parent asking why the report says
 * their child missed a class they attended.
 */
final class SessionGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::factory()->create(['slug' => 'sessions-test']),
        );
    }

    #[Test]
    public function local_class_time_survives_a_clock_change_while_the_instant_moves(): void
    {
        // Toronto puts its clocks back on Sunday 1 November 2026. A Saturday 09:00 class stays at
        // 09:00 for the families attending; the UTC instant it maps to shifts by an hour.
        $this->inTenant(function (): void {
            $batch = $this->batchWith('America/Toronto', '2026-10-01', '2026-12-31');
            $this->slot($batch, Weekday::Saturday, '09:00:00');

            app(SessionGenerator::class)->generate(
                $batch,
                CarbonImmutable::parse('2026-10-24', 'America/Toronto'),
                CarbonImmutable::parse('2026-11-14', 'America/Toronto'),
            );

            $sessions = ClassSession::query()->orderBy('session_local_date')->get()
                ->keyBy(fn (ClassSession $s) => $s->session_local_date->toDateString());

            // Before the change: 09:00 Toronto is 13:00 UTC (EDT, UTC−4).
            $this->assertSame('13:00', $sessions['2026-10-24']->starts_at_utc->format('H:i'));
            $this->assertSame('13:00', $sessions['2026-10-31']->starts_at_utc->format('H:i'));

            // After it: 09:00 Toronto is 14:00 UTC (EST, UTC−5).
            $this->assertSame('14:00', $sessions['2026-11-07']->starts_at_utc->format('H:i'));
            $this->assertSame('14:00', $sessions['2026-11-14']->starts_at_utc->format('H:i'));

            // And every one of them still reads 09:00 to the people attending.
            foreach ($sessions as $session) {
                $this->assertSame(
                    '09:00',
                    $session->starts_at_utc->setTimezone('America/Toronto')->format('H:i'),
                    'Local class time must not drift across a clock change.',
                );
            }
        });
    }

    #[Test]
    public function southern_hemisphere_daylight_saving_moves_the_other_way(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batchWith('Australia/Sydney', '2026-09-01', '2026-11-30');
            $this->slot($batch, Weekday::Wednesday, '18:00:00');

            app(SessionGenerator::class)->generate(
                $batch,
                CarbonImmutable::parse('2026-09-30', 'Australia/Sydney'),
                CarbonImmutable::parse('2026-10-14', 'Australia/Sydney'),
            );

            foreach (ClassSession::query()->get() as $session) {
                $this->assertSame(
                    '18:00',
                    $session->starts_at_utc->setTimezone('Australia/Sydney')->format('H:i'),
                );
            }

            // Sydney goes forward on 4 October 2026: 18:00 local moves from 08:00 to 07:00 UTC.
            $before = ClassSession::query()->where('session_local_date', '2026-09-30')->first();
            $after = ClassSession::query()->where('session_local_date', '2026-10-07')->first();

            $this->assertSame('08:00', $before->starts_at_utc->format('H:i'));
            $this->assertSame('07:00', $after->starts_at_utc->format('H:i'));
        });
    }

    #[Test]
    public function a_forty_five_minute_offset_is_handled_exactly(): void
    {
        // Kathmandu is UTC+05:45. Code that assumes whole-hour offsets fails here and nowhere else.
        $this->inTenant(function (): void {
            $batch = $this->batchWith('Asia/Kathmandu', '2026-08-01', '2026-08-31');
            $this->slot($batch, Weekday::Saturday, '10:00:00');

            app(SessionGenerator::class)->generate(
                $batch,
                CarbonImmutable::parse('2026-08-01', 'Asia/Kathmandu'),
                CarbonImmutable::parse('2026-08-31', 'Asia/Kathmandu'),
            );

            $session = ClassSession::query()->orderBy('session_local_date')->first();

            $this->assertSame('04:15', $session->starts_at_utc->format('H:i'));
            $this->assertSame('06:15', $session->ends_at_utc->format('H:i'));
        });
    }

    #[Test]
    public function a_local_time_that_does_not_exist_is_refused_rather_than_nudged(): void
    {
        // Toronto goes forward at 02:00 on 8 March 2026 — 02:30 never happens that day. PHP would
        // happily return 03:30, which is a class starting at a time nobody agreed to.
        $this->inTenant(function (): void {
            $batch = $this->batchWith('America/Toronto', '2026-03-01', '2026-03-31');
            $this->slot($batch, Weekday::Sunday, '02:30:00');

            $result = app(SessionGenerator::class)->generate(
                $batch,
                CarbonImmutable::parse('2026-03-01', 'America/Toronto'),
                CarbonImmutable::parse('2026-03-15', 'America/Toronto'),
            );

            $skipped = $result->skippedFor('clock_change');

            $this->assertCount(1, $skipped);
            $this->assertSame('2026-03-08', $skipped[0]['date']);
            $this->assertStringContainsString('does not exist', $skipped[0]['detail']);

            // The other Sundays are unaffected.
            $this->assertSame(2, $result->createdCount());
        });
    }

    #[Test]
    public function running_generation_twice_creates_nothing_the_second_time(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batchWith('Asia/Kathmandu', '2026-08-01', '2026-08-31');
            $this->slot($batch, Weekday::Saturday, '10:00:00');

            $generator = app(SessionGenerator::class);
            $from = CarbonImmutable::parse('2026-08-01', 'Asia/Kathmandu');
            $to = CarbonImmutable::parse('2026-08-31', 'Asia/Kathmandu');

            $first = $generator->generate($batch, $from, $to);
            $second = $generator->generate($batch, $from, $to);

            $this->assertSame(5, $first->createdCount(), 'August 2026 has five Saturdays.');
            $this->assertSame(0, $second->createdCount());
            $this->assertCount(5, $second->skippedFor('already_exists'));
            $this->assertSame(5, ClassSession::query()->count());
        });
    }

    #[Test]
    public function closure_dates_are_skipped_and_named(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batchWith('Asia/Kathmandu', '2026-08-01', '2026-08-31');
            $this->slot($batch, Weekday::Saturday, '10:00:00');

            Holiday::query()->create([
                'branch_id' => $batch->branch_id,
                'date' => '2026-08-15',
                'name' => 'Sample public holiday',
                'blocks_sessions' => true,
            ]);

            $result = app(SessionGenerator::class)->generate(
                $batch,
                CarbonImmutable::parse('2026-08-01', 'Asia/Kathmandu'),
                CarbonImmutable::parse('2026-08-31', 'Asia/Kathmandu'),
            );

            $skipped = $result->skippedFor('holiday');

            $this->assertSame(4, $result->createdCount());
            $this->assertCount(1, $skipped);
            $this->assertSame('Sample public holiday', $skipped[0]['detail']);
        });
    }

    #[Test]
    public function generation_stays_inside_the_batch_dates(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batchWith('Asia/Kathmandu', '2026-08-10', '2026-08-20');
            $this->slot($batch, Weekday::Saturday, '10:00:00');

            $result = app(SessionGenerator::class)->generate(
                $batch,
                CarbonImmutable::parse('2026-08-01', 'Asia/Kathmandu'),
                CarbonImmutable::parse('2026-08-31', 'Asia/Kathmandu'),
            );

            // Only 15 August falls inside 10–20 August.
            $this->assertSame(1, $result->createdCount());
            $this->assertNotEmpty($result->skippedFor('outside_batch_dates'));
        });
    }

    #[Test]
    public function a_retired_slot_stops_generating_from_its_end_date(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batchWith('Asia/Kathmandu', '2026-08-01', '2026-08-31');

            TimetableSlot::query()->create([
                'batch_id' => $batch->getKey(),
                'session_type_id' => SessionType::factory()->create()->getKey(),
                'weekday' => Weekday::Saturday,
                'start_time_local' => '10:00:00',
                'duration_min' => 120,
                'effective_to' => '2026-08-16',
            ]);

            $result = app(SessionGenerator::class)->generate(
                $batch,
                CarbonImmutable::parse('2026-08-01', 'Asia/Kathmandu'),
                CarbonImmutable::parse('2026-08-31', 'Asia/Kathmandu'),
            );

            $this->assertSame(3, $result->createdCount(), '1, 8 and 15 August only.');
            $this->assertNotEmpty($result->skippedFor('slot_not_effective'));
        });
    }

    #[Test]
    public function two_slots_on_the_same_day_both_generate(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batchWith('Asia/Kathmandu', '2026-08-01', '2026-08-31');
            $this->slot($batch, Weekday::Saturday, '10:00:00');
            $this->slot($batch, Weekday::Saturday, '14:00:00');

            $result = app(SessionGenerator::class)->generate(
                $batch,
                CarbonImmutable::parse('2026-08-01', 'Asia/Kathmandu'),
                CarbonImmutable::parse('2026-08-08', 'Asia/Kathmandu'),
            );

            $this->assertSame(4, $result->createdCount(), 'Two Saturdays, two slots each.');
        });
    }

    private function inTenant(callable $callback): void
    {
        app(TenantContext::class)->runAs($this->tenant, $callback);
    }

    private function batchWith(string $timezone, string $startsOn, string $endsOn): Batch
    {
        $brand = Brand::factory()->create();
        $branch = Branch::factory()->create(['brand_id' => $brand->getKey(), 'timezone' => $timezone]);
        $course = Course::factory()->create(['brand_id' => $brand->getKey()]);

        return Batch::factory()->create([
            'course_id' => $course->getKey(),
            'branch_id' => $branch->getKey(),
            'timezone' => $timezone,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }

    private function slot(Batch $batch, Weekday $day, string $time): TimetableSlot
    {
        return TimetableSlot::query()->create([
            'batch_id' => $batch->getKey(),
            'session_type_id' => SessionType::factory()->create()->getKey(),
            'weekday' => $day,
            'start_time_local' => $time,
            'duration_min' => 120,
        ]);
    }
}
