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
use App\Models\GradingScheme;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\SubmissionStatus;
use App\Models\Tenant;
use App\Models\TypeWeight;
use App\Services\AssessmentService;
use App\Services\GradeBookService;
use App\Support\Grading\GradeBookCalculator;
use App\Support\Grading\PeriodAverage;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\PeriodService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SL-SRS-001 §7.1 acceptance: "a batch mixing a rubric project, points homework and a pass/fail
 * quiz produces a weighted period average matching a hand calculation, with Exempt rows excluded."
 *
 * The arithmetic is written out in each test so that anyone can check it with a calculator, which
 * is the only real defence when a parent disputes a number.
 */
final class WeightedAverageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Batch $batch;

    private Enrollment $enrollment;

    /** @var array<string, AssessmentType> */
    private array $types = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::factory()->create(['slug' => 'weights-test']),
        );

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->lookups();
            $this->scenario();
        });
    }

    #[Test]
    public function a_mixed_scheme_month_matches_the_hand_calculation(): void
    {
        $this->inTenant(function (): void {
            $homework = $this->points('Loops worksheet', 'HOMEWORK', 20);
            $project = $this->rubric('Robot maze project', 'PROJECT', [15, 10, 10, 5]);
            $quiz = $this->passFail('Vocabulary quiz', 'QUIZ');

            $this->grade($homework, ['raw_score' => 16]);                       // 16/20  = 80
            $this->grade($project, ['rubric_scores' => $this->scores($project, [13, 9, 8, 4])]); // 34/40 = 85
            $this->grade($quiz, ['passed' => true]);                            // Pass   = 100

            // 80 × 0.40  +  85 × 0.40  +  100 × 0.20
            //   32.0     +    34.0     +     20.0     =  86.0
            $average = $this->average();

            $this->assertSame(86.0, $average->percentage);
            $this->assertTrue($average->isWeighted);
            $this->assertSame(100.0, $average->weightsUsed);
            $this->assertSame(3, $average->graded);
        });
    }

    #[Test]
    public function an_exempt_result_leaves_the_denominator_and_the_weights_rescale(): void
    {
        $this->inTenant(function (): void {
            $homework = $this->points('Loops worksheet', 'HOMEWORK', 20);
            $project = $this->rubric('Robot maze project', 'PROJECT', [15, 10, 10, 5]);
            $quiz = $this->passFail('Vocabulary quiz', 'QUIZ');

            $this->grade($homework, ['raw_score' => 16]);   // 80
            $this->grade($project, [], 'EXEMPT');           // excluded entirely
            $this->grade($quiz, ['passed' => true]);        // 100

            // Only Homework (40) and Quiz (20) remain, so 60 points of weight apply:
            //   (80 × 40  +  100 × 20) ÷ 60  =  (3200 + 2000) ÷ 60  =  5200 ÷ 60  =  86.666…
            $average = $this->average();

            $this->assertSame(86.7, $average->percentage);
            $this->assertSame(60.0, $average->weightsUsed);
            $this->assertSame(1, $average->excluded);

            // Scoring the exempt project zero would have produced 52.0 and misrepresented a
            // learner who was excused from it.
            $this->assertNotSame(52.0, $average->percentage);

            $this->assertStringContainsString('rescaled', $average->explanation());
        });
    }

    #[Test]
    public function missing_work_scores_zero_and_stays_in_the_denominator(): void
    {
        $this->inTenant(function (): void {
            $homework = $this->points('Loops worksheet', 'HOMEWORK', 20);
            $project = $this->rubric('Robot maze project', 'PROJECT', [15, 10, 10, 5]);
            $quiz = $this->passFail('Vocabulary quiz', 'QUIZ');

            $this->grade($homework, ['raw_score' => 16]);   // 80
            $this->grade($project, [], 'MISSING');          // 0 — a fact about the month
            $this->grade($quiz, ['passed' => true]);        // 100

            //  80 × 0.40  +  0 × 0.40  +  100 × 0.20  =  32 + 0 + 20  =  52.0
            $average = $this->average();

            $this->assertSame(52.0, $average->percentage);
            $this->assertSame(1, $average->missing);
            $this->assertSame(100.0, $average->weightsUsed, 'Missing work does not remove its weight.');
        });
    }

    #[Test]
    public function several_results_of_one_type_are_averaged_before_being_weighted(): void
    {
        $this->inTenant(function (): void {
            $hw1 = $this->points('Homework one', 'HOMEWORK', 10);
            $hw2 = $this->points('Homework two', 'HOMEWORK', 10);
            $hw3 = $this->points('Homework three', 'HOMEWORK', 10);
            $project = $this->rubric('Project', 'PROJECT', [50]);
            $quiz = $this->passFail('Quiz', 'QUIZ');

            $this->grade($hw1, ['raw_score' => 10]);  // 100
            $this->grade($hw2, ['raw_score' => 8]);   // 80
            $this->grade($hw3, ['raw_score' => 6]);   // 60
            $this->grade($project, ['rubric_scores' => $this->scores($project, [25])]); // 50
            $this->grade($quiz, ['passed' => false]); // 0

            // Homework mean = (100 + 80 + 60) ÷ 3 = 80, then weighted once:
            //   80 × 0.40  +  50 × 0.40  +  0 × 0.20  =  32 + 20 + 0  =  52.0
            //
            // Three homeworks do not outvote one project simply by being more numerous.
            $this->assertSame(52.0, $this->average()->percentage);
        });
    }

    #[Test]
    public function a_course_without_weights_falls_back_to_a_plain_mean(): void
    {
        $this->inTenant(function (): void {
            TypeWeight::query()->delete();

            $homework = $this->points('Loops worksheet', 'HOMEWORK', 20);
            $quiz = $this->passFail('Vocabulary quiz', 'QUIZ');

            $this->grade($homework, ['raw_score' => 16]);  // 80
            $this->grade($quiz, ['passed' => true]);       // 100

            $average = $this->average();

            $this->assertSame(90.0, $average->percentage);
            $this->assertFalse($average->isWeighted);
            $this->assertStringContainsString('Simple average', $average->explanation());
        });
    }

    #[Test]
    public function ungraded_work_is_reported_rather_than_folded_into_the_figure(): void
    {
        $this->inTenant(function (): void {
            $homework = $this->points('Loops worksheet', 'HOMEWORK', 20);
            $this->rubric('Robot maze project', 'PROJECT', [40]);   // never graded

            $this->grade($homework, ['raw_score' => 16]);

            $average = $this->average();

            $this->assertSame(80.0, $average->percentage);
            $this->assertSame(1, $average->ungraded);
            $this->assertFalse($average->isComplete(), 'Reports check this before sending.');
        });
    }

    #[Test]
    public function work_due_before_a_learner_joined_is_not_theirs_to_answer_for(): void
    {
        $this->inTenant(function (): void {
            $early = $this->points('Early homework', 'HOMEWORK', 20, '2026-08-05');
            $late = $this->points('Later homework', 'HOMEWORK', 20, '2026-08-25');

            $this->enrollment->update(['enrolled_on' => '2026-08-20']);
            $this->grade($late, ['raw_score' => 18]);

            $average = $this->average();

            $this->assertSame(90.0, $average->percentage);
            $this->assertSame(0, $average->ungraded, 'The earlier assessment is not their gap.');

            unset($early);
        });
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @param Closure(): void $callback */
    private function inTenant(Closure $callback): void
    {
        app(TenantContext::class)->runAs($this->tenant, $callback);
    }

    private function average(): PeriodAverage
    {
        $period = app(PeriodService::class)->forLabel($this->batch->load('course'), '2026-08');

        return app(GradeBookCalculator::class)->forPeriod(
            $this->enrollment->setRelation('batch', $this->batch),
            $period,
        );
    }

    private function lookups(): void
    {
        LearnerStatus::query()->firstOrCreate(['code' => 'ACTIVE'], ['name' => 'Active', 'sort' => 0]);
        EnrollmentStatus::query()->firstOrCreate(['code' => 'ACTIVE'],
            ['name' => 'Active', 'is_active_for_billing' => true, 'sort' => 0]);

        foreach ([
            ['Submitted', 'SUBMITTED', true, false, false],
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

    private function scenario(): void
    {
        $brand = Brand::factory()->create();
        $branch = Branch::factory()->create(['brand_id' => $brand->getKey(), 'timezone' => 'Asia/Kathmandu']);
        $course = Course::factory()->create(['brand_id' => $brand->getKey()]);

        $this->batch = Batch::factory()->create([
            'course_id' => $course->getKey(),
            'branch_id' => $branch->getKey(),
            'timezone' => 'Asia/Kathmandu',
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
        ]);

        // Homework 40%, Project 40%, Quiz 20% — the weighting from the design documents.
        foreach ([['Homework', 'HOMEWORK', 40], ['Project', 'PROJECT', 40], ['Quiz', 'QUIZ', 20]] as $i => [$name, $code, $weight]) {
            $type = AssessmentType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'sort' => $i]);
            $this->types[$code] = $type;

            TypeWeight::query()->create([
                'course_id' => $course->getKey(),
                'assessment_type_id' => $type->getKey(),
                'weight_pct' => $weight,
            ]);
        }

        $learner = Learner::factory()->create([
            'status_id' => LearnerStatus::query()->where('code', 'ACTIVE')->first()->getKey(),
        ]);

        $this->enrollment = Enrollment::factory()->create([
            'learner_id' => $learner->getKey(),
            'batch_id' => $this->batch->getKey(),
            'status_id' => EnrollmentStatus::query()->where('code', 'ACTIVE')->first()->getKey(),
            'enrolled_on' => '2026-08-01',
        ]);
    }

    private function points(string $title, string $typeCode, float $max, string $due = '2026-08-15'): Assessment
    {
        return $this->make($title, $typeCode, GradingSchemeKind::Points, ['max_points' => $max], [], [], $due);
    }

    /** @param array<int, float> $criteria */
    private function rubric(string $title, string $typeCode, array $criteria, string $due = '2026-08-15'): Assessment
    {
        $definitions = [];

        foreach ($criteria as $i => $max) {
            $definitions[] = ['name' => 'Criterion '.($i + 1), 'max_points' => $max];
        }

        return $this->make($title, $typeCode, GradingSchemeKind::Rubric, [], $definitions, [], $due);
    }

    private function passFail(string $title, string $typeCode, string $due = '2026-08-15'): Assessment
    {
        return $this->make($title, $typeCode, GradingSchemeKind::PassFail, [], [], [], $due);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, array<string, mixed>> $criteria
     * @param array<string, mixed> $config
     */
    private function make(
        string $title,
        string $typeCode,
        GradingSchemeKind $kind,
        array $attributes,
        array $criteria,
        array $config,
        string $due,
    ): Assessment {
        $scheme = GradingScheme::query()->create([
            'name' => $kind->label(),
            'code' => strtoupper($kind->value).'-'.uniqid(),
            'kind' => $kind,
            'config' => $config,
        ]);

        $assessment = app(AssessmentService::class)->create(
            $this->batch,
            array_merge([
                'assessment_type_id' => $this->types[$typeCode]->getKey(),
                'grading_scheme_id' => $scheme->getKey(),
                'title' => $title,
                'due_local_date' => $due,
            ], $attributes),
            $criteria,
        );

        return app(AssessmentService::class)->publish($assessment);
    }

    /** @param array<string, mixed> $input */
    private function grade(Assessment $assessment, array $input, string $statusCode = 'SUBMITTED'): void
    {
        app(GradeBookService::class)->saveGrid($this->batch, [
            array_merge($input, [
                'assessment_id' => $assessment->getKey(),
                'enrollment_id' => $this->enrollment->getKey(),
                'submission_status_id' => SubmissionStatus::query()->where('code', $statusCode)->first()->getKey(),
            ]),
        ]);
    }

    /**
     * @param array<int, float> $points
     * @return array<int, float>
     */
    private function scores(Assessment $assessment, array $points): array
    {
        $scores = [];

        foreach ($assessment->rubricCriteria as $i => $criterion) {
            $scores[$criterion->getKey()] = $points[$i] ?? 0;
        }

        return $scores;
    }
}
