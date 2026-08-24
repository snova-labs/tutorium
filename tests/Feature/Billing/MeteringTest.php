<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\Operator;
use App\Models\Tenant;
use App\Models\UsageSnapshot;
use App\Services\EnrollmentService;
use App\Services\MeteringService;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The number a customer pays for, and every rule that shapes it.
 *
 * These tests exist to be readable by someone who is not a programmer, because the questions they
 * answer are the questions a customer asks when they dispute an invoice.
 */
final class MeteringTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Batch $batchA;

    private Batch $batchB;

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
            $brand = Brand::query()->first();
            $branch = Branch::query()->first();
            $course = Course::factory()->create(['brand_id' => $brand->getKey()]);

            $this->batchA = Batch::factory()->create([
                'course_id' => $course->getKey(), 'branch_id' => $branch->getKey(),
                'code' => 'BATCH-A', 'capacity' => null,
            ]);
            $this->batchB = Batch::factory()->create([
                'course_id' => $course->getKey(), 'branch_id' => $branch->getKey(),
                'code' => 'BATCH-B', 'capacity' => null,
            ]);
        });
    }

    #[Test]
    public function a_learner_in_three_classes_counts_once(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $learner = $this->learner('Sample Learner 01');

            app(EnrollmentService::class)->enroll($learner, $this->batchA);
            app(EnrollmentService::class)->enroll($learner, $this->batchB);
        });

        $snapshot = app(MeteringService::class)->snapshot($this->tenant);

        $this->assertSame(1, $snapshot->active_learners, 'Two enrollments, one person, one charge.');
    }

    #[Test]
    public function only_statuses_flagged_for_billing_are_counted(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $first = app(EnrollmentService::class)->enroll($this->learner('Sample Learner 01'), $this->batchA);
            app(EnrollmentService::class)->enroll($this->learner('Sample Learner 02'), $this->batchA);

            app(EnrollmentService::class)->changeStatus(
                $first,
                EnrollmentStatus::query()->where('code', 'ON_HOLD')->first(),
                'Family away until October',
            );
        });

        $this->assertSame(1, app(MeteringService::class)->snapshot($this->tenant)->active_learners);
    }

    #[Test]
    public function withdrawn_learners_never_count_so_keeping_history_is_free(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $enrollment = app(EnrollmentService::class)->enroll($this->learner('Sample Learner 01'), $this->batchA);
            app(EnrollmentService::class)->withdraw($enrollment, 'Moved city');
        });

        $snapshot = app(MeteringService::class)->snapshot($this->tenant);

        // Charging for archives only teaches people to delete the evidence their reports rest on.
        $this->assertSame(0, $snapshot->active_learners);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertSame(1, Enrollment::query()->count(), 'The record itself is kept.');
        });
    }

    #[Test]
    public function the_billable_quantity_is_the_peak_not_the_average(): void
    {
        $this->measure('2026-08-01', 10);
        $this->measure('2026-08-14', 24);
        $this->measure('2026-08-31', 12);

        $peak = app(MeteringService::class)->peakFor(
            $this->tenant,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        // The average would be 15 and the closing figure 12. Neither is what was used.
        $this->assertSame(24, $peak['quantity']);
        $this->assertSame('2026-08-14', $peak['snapshot']->snapshot_date->toDateString());
    }

    #[Test]
    public function measuring_the_same_day_twice_changes_nothing(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            app(EnrollmentService::class)->enroll($this->learner('Sample Learner 01'), $this->batchA);
        });

        $first = app(MeteringService::class)->snapshot($this->tenant);
        $second = app(MeteringService::class)->snapshot($this->tenant);

        $this->assertTrue($first->is($second));

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertSame(1, UsageSnapshot::query()->count());
        });
    }

    #[Test]
    public function a_snapshot_cannot_be_edited(): void
    {
        $snapshot = app(MeteringService::class)->snapshot($this->tenant);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('immutable');

        $snapshot->update(['active_learners' => 999]);
    }

    #[Test]
    public function a_correction_leaves_both_figures_readable(): void
    {
        $operator = Operator::factory()->create();
        $original = $this->measure('2026-08-19', 249);

        $correction = app(MeteringService::class)->correct(
            $this->tenant,
            $original,
            247,
            'Duplicate enrollment created during an import',
            $operator,
        );

        app(TenantContext::class)->runAs($this->tenant, function () use ($original, $correction): void {
            $this->assertSame(249, UsageSnapshot::query()->find($original->getKey())->active_learners);
            $this->assertSame(247, $correction->active_learners);
            $this->assertSame($original->getKey(), $correction->corrects_snapshot_id);
        });

        // And the corrected figure is the one billing uses.
        $peak = app(MeteringService::class)->peakFor(
            $this->tenant,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        $this->assertSame(247, $peak['quantity']);
        $this->assertTrue($peak['snapshot']->is_correction);
    }

    #[Test]
    public function a_missed_day_is_reported_rather_than_hidden(): void
    {
        $this->measure('2026-08-01', 10);
        $this->measure('2026-08-03', 12);

        $this->travelTo('2026-08-05 12:00:00');

        $peak = app(MeteringService::class)->peakFor(
            $this->tenant,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        $this->assertSame(12, $peak['quantity']);
        $this->assertSame(2, $peak['days_measured']);
        $this->assertContains('2026-08-02', $peak['missing_days']);
    }

    #[Test]
    public function a_missed_day_can_be_reconstructed_from_enrollment_history(): void
    {
        app(TenantContext::class)->runAs($this->tenant, function (): void {
            app(EnrollmentService::class)->enroll($this->learner('Sample Learner 01'), $this->batchA);
            app(EnrollmentService::class)->enroll($this->learner('Sample Learner 02'), $this->batchA);
        });

        $backfilled = app(MeteringService::class)->backfill($this->tenant, CarbonImmutable::now());

        // Reconstructed from status history rather than guessed — which is the entire reason that
        // table stores rows instead of a single current status.
        $this->assertSame(2, $backfilled->active_learners);
        $this->assertTrue($backfilled->is_correction);
        $this->assertStringContainsString('Backfilled', $backfilled->correction_reason);
    }

    #[Test]
    public function the_live_meter_shows_the_figure_that_will_be_billed(): void
    {
        $this->measure(CarbonImmutable::now()->startOfMonth()->toDateString(), 18);

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            app(EnrollmentService::class)->enroll($this->learner('Sample Learner 01'), $this->batchA);
        });

        $meter = app(MeteringService::class)->liveMeter($this->tenant);

        $this->assertSame(18, $meter['peak']);
        $this->assertSame(18, $meter['will_be_billed_for']);
        $this->assertStringContainsString('highest daily count', $meter['basis']);
    }

    private function learner(string $name): Learner
    {
        return Learner::factory()->create([
            'legal_name' => $name,
            'status_id' => LearnerStatus::query()->where('code', 'ACTIVE')->first()->getKey(),
        ]);
    }

    private function measure(string $date, int $count): UsageSnapshot
    {
        return app(TenantContext::class)->runAs($this->tenant, fn () => UsageSnapshot::query()->create([
            'snapshot_date' => $date,
            'active_learners' => $count,
        ]));
    }
}
