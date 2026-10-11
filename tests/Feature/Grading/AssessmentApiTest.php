<?php

declare(strict_types=1);

namespace Tests\Feature\Grading;

use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Batch;
use App\Models\Brand;
use App\Models\Course;
use App\Models\GradingScheme;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Adding an assessment from the grade book: what the client sends, and what comes back to mark. */
final class AssessmentApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Assessment Academy', 'owner_name' => 'Sample Owner', 'owner_email' => 'owner@assess.test',
            'password' => 'a-long-enough-password', 'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
        ])['tenant'];

        Sanctum::actingAs($this->owner(), ['staff']);

        $brand = $this->in(fn () => Brand::query()->firstOrFail());
        $branch = $this->getJson('/api/v1/branches')->json('data.0.id');

        $course = $this->postJson('/api/v1/courses', [
            'brand_id' => $brand->id, 'name' => 'Mathematics', 'code' => 'MATH', 'period_type' => 'monthly',
        ])->assertCreated()->json('data.id');

        $this->batch = $this->postJson('/api/v1/batches', [
            'course_id' => $course, 'branch_id' => $branch, 'name' => 'Morning', 'code' => 'AM',
            'starts_on' => now()->startOfMonth()->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    #[Test]
    public function the_vocabularies_offer_what_the_form_picks_from(): void
    {
        $data = $this->getJson('/api/v1/grading/vocabularies')->assertOk()->json('data');

        $this->assertNotEmpty($data['assessment_types']);
        $this->assertNotEmpty($data['grading_schemes']);

        // Whether a maximum belongs to the assessment is the scheme's to say, not the form's to
        // guess: points carry one, a rubric carries one per criterion instead.
        $this->assertTrue($this->scheme('points')['needs_maximum']);
        $this->assertSame('rubric', $this->scheme('rubric')['kind']);
    }

    #[Test]
    public function an_assessment_added_from_the_grade_book_is_there_to_mark(): void
    {
        $due = now()->startOfMonth()->addDays(13)->toDateString();

        $created = $this->postJson("/api/v1/batches/{$this->batch}/assessments", [
            'assessment_type_id' => $this->kind(),
            'grading_scheme_id' => $this->scheme('points')['id'],
            'title' => 'Unit quiz', 'max_points' => 20, 'due_local_date' => $due, 'publish' => true,
        ])->assertCreated()->assertJsonPath('data.is_published', true)->json('data');

        // Published, so it is a column in the grade book the person is looking at.
        $grid = $this->getJson("/api/v1/batches/{$this->batch}/gradebook")->assertOk()->json('data');

        $this->assertContains($created['id'], array_column($grid['assessments'], 'id'));
        $this->assertSame('Unit quiz', $grid['assessments'][0]['title']);
        $this->assertSame($due, $grid['assessments'][0]['due_local_date']);
    }

    #[Test]
    public function a_rubric_keeps_the_criteria_it_was_given(): void
    {
        $id = $this->postJson("/api/v1/batches/{$this->batch}/assessments", [
            'assessment_type_id' => $this->kind(),
            'grading_scheme_id' => $this->scheme('rubric')['id'],
            'title' => 'Speaking task',
            // A date is what puts it in a period's grade book; one without never shows up.
            'due_local_date' => now()->startOfMonth()->addDays(6)->toDateString(),
            'publish' => true,
            'criteria' => [
                ['name' => 'Fluency', 'max_points' => 10, 'descriptor' => 'Speaks without long pauses.'],
                ['name' => 'Accuracy', 'max_points' => 15],
            ],
        ])->assertCreated()->json('data.id');

        $criteria = $this->in(fn () => Assessment::query()->findOrFail($id)->rubricCriteria()->orderBy('id')->get());

        $this->assertSame(['Fluency', 'Accuracy'], $criteria->pluck('name')->all());
        $this->assertSame('Speaks without long pauses.', $criteria->first()->descriptor);

        // The grid carries them, because a rubric is marked per criterion rather than once.
        $grid = $this->getJson("/api/v1/batches/{$this->batch}/gradebook")->json('data');
        $this->assertCount(2, $grid['assessments'][0]['criteria']);
    }

    #[Test]
    public function an_unpublished_assessment_stays_out_of_the_grade_book(): void
    {
        $this->postJson("/api/v1/batches/{$this->batch}/assessments", [
            'assessment_type_id' => $this->kind(),
            'grading_scheme_id' => $this->scheme('points')['id'],
            'title' => 'Draft quiz', 'max_points' => 10, 'due_local_date' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.is_published', false);

        $this->assertSame([], $this->getJson("/api/v1/batches/{$this->batch}/gradebook")->json('data.assessments'));
    }

    #[Test]
    public function a_points_assessment_without_a_maximum_is_refused(): void
    {
        // The client checks this first; the API is what makes it true.
        $this->postJson("/api/v1/batches/{$this->batch}/assessments", [
            'assessment_type_id' => $this->kind(),
            'grading_scheme_id' => $this->scheme('points')['id'],
            'title' => 'No maximum', 'max_points' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('max_points');
    }

    #[Test]
    public function a_teacher_who_may_not_manage_assessments_cannot_add_one(): void
    {
        $payload = [
            'assessment_type_id' => $this->kind(),
            'grading_scheme_id' => $this->scheme('points')['id'],
            'title' => 'Not mine', 'max_points' => 10,
        ];

        $frontDesk = $this->in(function (): User {
            $user = User::factory()->create(['is_active' => true, 'scope_all_branches' => true]);
            $user->syncRoles(['Front desk']);

            return $user->fresh();
        });

        Sanctum::actingAs($frontDesk, ['staff']);

        $this->postJson("/api/v1/batches/{$this->batch}/assessments", $payload)->assertForbidden();

        $this->assertSame(0, $this->in(fn () => Assessment::query()->count()));
    }

    #[Test]
    public function a_class_in_another_academy_is_not_found(): void
    {
        $other = app(TenantProvisioner::class)->provision([
            'name' => 'Other Academy', 'owner_name' => 'Sample Owner', 'owner_email' => 'owner@other-assess.test',
            'password' => 'a-long-enough-password', 'timezone' => 'Asia/Kathmandu', 'preset_code' => 'blank',
        ])['tenant'];

        $theirBatch = app(TenantContext::class)->runAs($other, function (): Batch {
            $course = Course::factory()->create();

            return Batch::factory()->create(['course_id' => $course->getKey()]);
        });

        $scheme = $this->in(fn () => GradingScheme::query()->firstOrFail());

        // Not-found, never forbidden: a 403 would confirm the class exists somewhere.
        $this->postJson("/api/v1/batches/{$theirBatch->getKey()}/assessments", [
            'assessment_type_id' => 1, 'grading_scheme_id' => $scheme->getKey(), 'title' => 'Theirs', 'max_points' => 10,
        ])->assertNotFound();
    }

    /** The first assessment type this academy's preset gave it. */
    private function kind(): int
    {
        return (int) $this->in(fn () => AssessmentType::query()->orderBy('sort')->firstOrFail()->getKey());
    }

    /**
     * A scheme as the client sees it, read through the endpoint its form reads.
     *
     * @return array{id: int, kind: string, needs_maximum: bool}
     */
    private function scheme(string $kind): array
    {
        $schemes = $this->getJson('/api/v1/grading/vocabularies')->assertOk()->json('data.grading_schemes');
        $this->assertIsArray($schemes);

        foreach ($schemes as $scheme) {
            $this->assertIsArray($scheme);

            if ($scheme['kind'] === $kind) {
                return ['id' => (int) $scheme['id'], 'kind' => $kind, 'needs_maximum' => (bool) $scheme['needs_maximum']];
            }
        }

        $this->fail("This academy has no {$kind} scheme.");
    }

    private function owner(): User
    {
        return $this->in(fn () => User::query()->firstOrFail());
    }

    /**
     * @template T
     *
     * @param Closure(): T $run
     * @return T
     */
    private function in(Closure $run): mixed
    {
        return app(TenantContext::class)->runAs($this->tenant, $run);
    }
}
