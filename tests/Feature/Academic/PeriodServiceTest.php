<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Enums\PeriodType;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Course;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Period boundaries are computed in the batch timezone and nowhere else.
 *
 * The defect these exist to prevent: a Saturday evening class on the last day of the month
 * falling into the following month's report because UTC had already rolled over.
 */
final class PeriodServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::factory()->create(['slug' => 'periods-test']),
        );
    }

    #[Test]
    public function a_monthly_period_runs_from_local_midnight_to_local_midnight(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batch('America/Toronto', PeriodType::Monthly);

            $period = app(PeriodService::class)->forLabel($batch, '2026-08');

            $this->assertSame('2026-08', $period->label);
            $this->assertSame('2026-08-01', $period->startsLocalDate->toDateString());
            $this->assertSame('2026-08-31', $period->endsLocalDate->toDateString());

            // Toronto is UTC−4 in August, so the window opens at 04:00 UTC on the 1st and closes
            // at 03:59:59 UTC on 1 September.
            $this->assertSame('2026-08-01 04:00', $period->startsAtUtc->format('Y-m-d H:i'));
            $this->assertSame('2026-09-01 03:59', $period->endsAtUtc->format('Y-m-d H:i'));
        });
    }

    #[Test]
    public function a_late_evening_session_stays_in_the_month_it_was_taught(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batch('America/Toronto', PeriodType::Monthly);
            $period = app(PeriodService::class)->forLabel($batch, '2026-08');

            // 21:00 on 31 August in Toronto is already 01:00 on 1 September in UTC. Computing the
            // boundary in UTC would push this class into September's report.
            $lateClass = CarbonImmutable::parse('2026-08-31 21:00', 'America/Toronto')->utc();

            $this->assertTrue(
                $period->contains($lateClass),
                'A class taught in August must be reported in August.',
            );
        });
    }

    #[Test]
    public function a_forty_five_minute_offset_produces_exact_boundaries(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batch('Asia/Kathmandu', PeriodType::Monthly);
            $period = app(PeriodService::class)->forLabel($batch, '2026-08');

            $this->assertSame('2026-07-31 18:15', $period->startsAtUtc->format('Y-m-d H:i'));
            $this->assertSame('2026-08-31 18:14', $period->endsAtUtc->format('Y-m-d H:i'));
        });
    }

    #[Test]
    public function quarters_can_be_anchored_to_a_financial_year(): void
    {
        $this->inTenant(function (): void {
            // An April-anchored year: April–June is Q1, July–September is Q2.
            $batch = $this->batch('UTC', PeriodType::Quarter, ['period_anchor_month' => 4]);
            $service = app(PeriodService::class);

            $q1 = $service->forDate($batch, CarbonImmutable::parse('2026-05-15', 'UTC'));
            $this->assertSame('2026-04-01', $q1->startsLocalDate->toDateString());
            $this->assertSame('2026-06-30', $q1->endsLocalDate->toDateString());

            $q2 = $service->forDate($batch, CarbonImmutable::parse('2026-08-15', 'UTC'));
            $this->assertSame('2026-07-01', $q2->startsLocalDate->toDateString());
            $this->assertSame('2026-09-30', $q2->endsLocalDate->toDateString());
        });
    }

    #[Test]
    public function blocks_are_counted_from_the_batch_start(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batch('UTC', PeriodType::Block, ['period_block_weeks' => 4], '2026-08-03');
            $service = app(PeriodService::class);

            $first = $service->forDate($batch, CarbonImmutable::parse('2026-08-10', 'UTC'));
            $this->assertSame('Block 1', $first->label);
            $this->assertSame('2026-08-03', $first->startsLocalDate->toDateString());
            $this->assertSame('2026-08-30', $first->endsLocalDate->toDateString());

            $second = $service->forDate($batch, CarbonImmutable::parse('2026-09-05', 'UTC'));
            $this->assertSame('Block 2', $second->label);
            $this->assertSame('2026-08-31', $second->startsLocalDate->toDateString());
        });
    }

    #[Test]
    public function term_periods_are_read_rather_than_computed(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batch('Europe/Berlin', PeriodType::Term);

            ReportingPeriod::query()->create([
                'batch_id' => $batch->getKey(),
                'course_id' => $batch->course_id,
                'type' => PeriodType::Term,
                'label' => 'Autumn 2026',
                'starts_local_date' => '2026-09-01',
                'ends_local_date' => '2026-12-15',
            ]);

            $period = app(PeriodService::class)->forDate($batch, CarbonImmutable::parse('2026-10-04', 'Europe/Berlin'));

            $this->assertSame('Autumn 2026', $period->label);
            $this->assertSame(106, $period->days());
        });
    }

    #[Test]
    public function an_undefined_term_fails_with_something_a_person_can_act_on(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batch('Europe/Berlin', PeriodType::Term);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Define one before reporting on it');

            app(PeriodService::class)->forDate($batch, CarbonImmutable::parse('2026-10-04', 'Europe/Berlin'));
        });
    }

    #[Test]
    public function materialising_a_period_is_idempotent(): void
    {
        $this->inTenant(function (): void {
            $batch = $this->batch('Asia/Kathmandu', PeriodType::Monthly);
            $service = app(PeriodService::class);
            $boundary = $service->forLabel($batch, '2026-08');

            $first = $service->ensure($batch, $boundary);
            $second = $service->ensure($batch, $boundary);

            $this->assertTrue($first->is($second));
            $this->assertSame(1, ReportingPeriod::query()->count());
        });
    }

    private function inTenant(callable $callback): void
    {
        app(TenantContext::class)->runAs($this->tenant, $callback);
    }

    /** @param array<string, mixed> $courseAttributes */
    private function batch(
        string $timezone,
        PeriodType $type,
        array $courseAttributes = [],
        string $startsOn = '2026-01-01',
    ): Batch {
        $brand = Brand::factory()->create();
        $branch = Branch::factory()->create(['brand_id' => $brand->getKey(), 'timezone' => $timezone]);
        $course = Course::factory()->create(array_merge([
            'brand_id' => $brand->getKey(),
            'period_type' => $type,
        ], $courseAttributes));

        return Batch::factory()->create([
            'course_id' => $course->getKey(),
            'branch_id' => $branch->getKey(),
            'timezone' => $timezone,
            'starts_on' => $startsOn,
            'ends_on' => '2026-12-31',
        ])->load('course');
    }
}
