<?php

declare(strict_types=1);

namespace Tests\Feature\Grading;

use App\Enums\GradingSchemeKind;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Grade;
use App\Models\GradingScheme;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\SubmissionStatus;
use App\Models\Tenant;
use App\Services\AssessmentService;
use App\Services\GradeBookService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every scheme normalises to a comparable percentage, so that a rubric, a points score and a CEFR
 * level can sit in one average without any of them being distorted.
 */
final class GradingNormalisationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::factory()->create(['slug' => 'grading-test']),
        );
    }

    #[Test]
    public function points_normalise_against_the_assessments_own_maximum(): void
    {
        $this->inTenant(function (): void {
            $assessment = $this->assessment(GradingSchemeKind::Points, ['max_points' => 20]);

            $this->assertSame('85.00', $this->gradeWith($assessment, ['raw_score' => 17]));
            $this->assertSame('50.00', $this->gradeWith($assessment, ['raw_score' => 10]));
        });
    }

    #[Test]
    public function a_rubric_sums_its_criteria_and_normalises_against_the_total(): void
    {
        $this->inTenant(function (): void {
            $assessment = $this->assessment(GradingSchemeKind::Rubric, [], [
                ['name' => 'Runs without errors', 'max_points' => 15],
                ['name' => 'Readable and commented', 'max_points' => 10],
                ['name' => 'Uses a loop', 'max_points' => 10],
                ['name' => 'Explained to the class', 'max_points' => 5],
            ]);

            // 15 + 8 + 8 + 3 = 34 out of 40.
            $normalized = $this->gradeWith($assessment, [
                'rubric_scores' => $this->criteriaScores($assessment, [15, 8, 8, 3]),
            ]);

            $this->assertSame('85.00', $normalized);
        });
    }

    #[Test]
    public function pass_and_fail_normalise_to_configurable_values(): void
    {
        $this->inTenant(function (): void {
            $assessment = $this->assessment(GradingSchemeKind::PassFail);

            $this->assertSame('100.00', $this->gradeWith($assessment, ['passed' => true]));
            $this->assertSame('0.00', $this->gradeWith($assessment, ['passed' => false]));
        });
    }

    #[Test]
    public function a_scale_where_one_is_best_normalises_correctly(): void
    {
        $this->inTenant(function (): void {
            // German 1–6. Nothing downstream needs to know the scale runs backwards, because the
            // band carries its own value.
            $assessment = $this->assessment(GradingSchemeKind::Letter, [], [], [
                'bands' => [
                    ['label' => '1', 'value' => 100],
                    ['label' => '2', 'value' => 80],
                    ['label' => '3', 'value' => 65],
                    ['label' => '4', 'value' => 50],
                    ['label' => '5', 'value' => 25],
                    ['label' => '6', 'value' => 0],
                ],
            ]);

            $this->assertSame('100.00', $this->gradeWith($assessment, ['letter' => '1']));
            $this->assertSame('50.00', $this->gradeWith($assessment, ['letter' => '4']));
            $this->assertSame('0.00', $this->gradeWith($assessment, ['letter' => '6']));
        });
    }

    #[Test]
    public function a_letter_band_without_a_value_uses_its_midpoint(): void
    {
        $this->inTenant(function (): void {
            $assessment = $this->assessment(GradingSchemeKind::Letter, [], [], [
                'bands' => [
                    ['label' => 'A', 'min' => 85, 'max' => 100],
                    ['label' => 'B', 'min' => 70, 'max' => 84],
                ],
            ]);

            $this->assertSame('92.50', $this->gradeWith($assessment, ['letter' => 'A']));
            $this->assertSame('77.00', $this->gradeWith($assessment, ['letter' => 'B']));
        });
    }

    #[Test]
    public function a_level_ladder_normalises_by_position(): void
    {
        $this->inTenant(function (): void {
            $assessment = $this->assessment(GradingSchemeKind::Level, [], [], [
                'ladder' => ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'],
            ]);

            $this->assertSame('0.00', $this->gradeWith($assessment, ['level_code' => 'A1']));
            $this->assertSame('40.00', $this->gradeWith($assessment, ['level_code' => 'B1']));
            $this->assertSame('100.00', $this->gradeWith($assessment, ['level_code' => 'C2']));
        });
    }

    #[Test]
    public function exempt_work_stores_no_comparable_value_at_all(): void
    {
        $this->inTenant(function (): void {
            $assessment = $this->assessment(GradingSchemeKind::Points, ['max_points' => 20]);

            $this->assertNull($this->gradeWith($assessment, ['raw_score' => 17], 'EXEMPT'));
        });
    }

    #[Test]
    public function missing_work_is_a_zero_rather_than_an_absence(): void
    {
        $this->inTenant(function (): void {
            $assessment = $this->assessment(GradingSchemeKind::Points, ['max_points' => 20]);

            $this->assertSame('0.00', $this->gradeWith($assessment, [], 'MISSING'));
        });
    }

    #[Test]
    public function a_rubric_score_above_its_criterion_maximum_is_refused(): void
    {
        $this->inTenant(function (): void {
            $assessment = $this->assessment(GradingSchemeKind::Rubric, [], [
                ['name' => 'Runs without errors', 'max_points' => 15],
            ]);

            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('scored out of 15');

            $this->gradeWith($assessment, [
                'rubric_scores' => $this->criteriaScores($assessment, [20]),
            ]);
        });
    }

    #[Test]
    public function a_letter_outside_the_scale_is_refused_with_the_options_listed(): void
    {
        $this->inTenant(function (): void {
            $assessment = $this->assessment(GradingSchemeKind::Letter, [], [], [
                'bands' => [['label' => 'A', 'value' => 92.5], ['label' => 'B', 'value' => 77]],
            ]);

            try {
                $this->gradeWith($assessment, ['letter' => 'Z']);
                $this->fail('An off-scale letter should be refused.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Use one of: A, B', $e->getMessage());
            }
        });
    }

    #[Test]
    public function a_points_assessment_without_a_maximum_is_refused(): void
    {
        $this->inTenant(function (): void {
            // The old system assumed 100. This one asks, because a worksheet out of 20 is the
            // common case and silently scoring it out of 100 would misreport every learner.
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('not assumed to be 100');

            $this->assessment(GradingSchemeKind::Points);
        });
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function inTenant(callable $callback): void
    {
        app(TenantContext::class)->runAs($this->tenant, function () use ($callback): void {
            $this->lookups();
            $callback();
        });
    }

    private function lookups(): void
    {
        LearnerStatus::query()->firstOrCreate(['code' => 'ACTIVE'], ['name' => 'Active', 'sort' => 0]);
        EnrollmentStatus::query()->firstOrCreate(['code' => 'ACTIVE'],
            ['name' => 'Active', 'is_active_for_billing' => true, 'sort' => 0]);
        AssessmentType::query()->firstOrCreate(['code' => 'HOMEWORK'], ['name' => 'Homework', 'sort' => 0]);

        foreach ([
            ['Submitted', 'SUBMITTED', true, false, false],
            ['Late', 'LATE_SUB', true, false, false],
            ['Missing', 'MISSING', false, false, true],
            ['Exempt', 'EXEMPT', false, true, false],
        ] as [$name, $code, $submitted, $excluded, $negative]) {
            SubmissionStatus::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'counts_as_submitted' => $submitted,
                'excluded_from_average' => $excluded,
                'is_negative' => $negative,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, array<string, mixed>> $criteria
     * @param array<string, mixed> $config
     */
    private function assessment(
        GradingSchemeKind $kind,
        array $attributes = [],
        array $criteria = [],
        array $config = [],
    ): Assessment {
        $scheme = GradingScheme::query()->create([
            'name' => $kind->label(),
            'code' => strtoupper($kind->value).'-'.uniqid(),
            'kind' => $kind,
            'config' => $config,
        ]);

        $assessment = app(AssessmentService::class)->create(
            $this->batch(),
            array_merge([
                'assessment_type_id' => AssessmentType::query()->where('code', 'HOMEWORK')->first()->getKey(),
                'grading_scheme_id' => $scheme->getKey(),
                'title' => 'Sample assessment',
                'due_local_date' => '2026-08-15',
            ], $attributes),
            $criteria,
        );

        return app(AssessmentService::class)->publish($assessment);
    }

    private ?Batch $batch = null;

    private function batch(): Batch
    {
        if ($this->batch !== null) {
            return $this->batch;
        }

        $brand = Brand::factory()->create();
        $branch = Branch::factory()->create(['brand_id' => $brand->getKey(), 'timezone' => 'Asia/Kathmandu']);
        $course = Course::factory()->create(['brand_id' => $brand->getKey()]);

        return $this->batch = Batch::factory()->create([
            'course_id' => $course->getKey(),
            'branch_id' => $branch->getKey(),
            'timezone' => 'Asia/Kathmandu',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
        ]);
    }

    private ?Enrollment $enrollment = null;

    private function enrollment(): Enrollment
    {
        if ($this->enrollment !== null) {
            return $this->enrollment;
        }

        $learner = Learner::factory()->create([
            'status_id' => LearnerStatus::query()->where('code', 'ACTIVE')->first()->getKey(),
        ]);

        return $this->enrollment = Enrollment::factory()->create([
            'learner_id' => $learner->getKey(),
            'batch_id' => $this->batch()->getKey(),
            'status_id' => EnrollmentStatus::query()->where('code', 'ACTIVE')->first()->getKey(),
            'enrolled_on' => '2026-08-01',
        ]);
    }

    /** @param array<string, mixed> $input */
    private function gradeWith(Assessment $assessment, array $input, string $statusCode = 'SUBMITTED'): ?string
    {
        app(GradeBookService::class)->saveGrid($this->batch(), [
            array_merge($input, [
                'assessment_id' => $assessment->getKey(),
                'enrollment_id' => $this->enrollment()->getKey(),
                'submission_status_id' => SubmissionStatus::query()->where('code', $statusCode)->first()->getKey(),
            ]),
        ]);

        return Grade::query()
            ->where('assessment_id', $assessment->getKey())
            ->where('enrollment_id', $this->enrollment()->getKey())
            ->value('normalized_pct');
    }

    /**
     * @param array<int, float> $points
     * @return array<int, float>
     */
    private function criteriaScores(Assessment $assessment, array $points): array
    {
        $scores = [];

        foreach ($assessment->rubricCriteria as $i => $criterion) {
            $scores[$criterion->getKey()] = $points[$i] ?? 0;
        }

        return $scores;
    }
}
