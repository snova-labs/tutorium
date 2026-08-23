<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\Report;
use App\Models\ReportTemplate;
use App\Support\Reporting\ReportRenderer;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\PeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Reporting\Concerns\BuildsReportingScenario;
use Tests\TestCase;

/**
 * A sent report is evidence, not a view.
 *
 * The figures a parent read must survive every correction made afterwards, because the alternative
 * is a conversation where nobody can establish what was actually said (FR-RPT-5).
 */
final class ReportSnapshotTest extends TestCase
{
    use BuildsReportingScenario, RefreshDatabase;

    #[Test]
    public function the_numbers_are_frozen_at_generation(): void
    {
        Storage::fake('local');

        $this->inTenant(function (): void {
            $this->markAllPresent();
            $this->gradeHomework(16);   // 16/20 = 80

            $report = $this->generate();

            $this->assertSame(80.0, $report->stats_snapshot['average']['percentage']);

            // A correction three weeks later, entirely legitimate.
            $this->gradeHomework(20);   // now 100

            $this->assertSame(
                80.0,
                Report::query()->find($report->getKey())->stats_snapshot['average']['percentage'],
                'A sent report must not silently change when a later grade is corrected.',
            );
        });
    }

    #[Test]
    public function re_rendering_reproduces_what_was_sent(): void
    {
        Storage::fake('local');

        $this->inTenant(function (): void {
            $this->markAllPresent();
            $this->gradeHomework(16);

            $report = $this->generate();
            $this->gradeHomework(20);

            // Downloaded six weeks later, it must match the PDF the parent received.
            $pdf = app(\App\Services\ReportService::class)->rerender($report->refresh());

            $this->assertStringStartsWith('%PDF', $pdf);
            $this->assertSame(80.0, $report->stats_snapshot['average']['percentage']);
        });
    }

    #[Test]
    public function a_period_with_gaps_is_refused_by_default(): void
    {
        Storage::fake('local');

        $this->inTenant(function (): void {
            // Attendance marked, but the homework never graded.
            $this->markAllPresent();

            try {
                $this->generate();
                $this->fail('An incomplete period should be refused.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('not graded', $e->getMessage());
                $this->assertStringContainsString('Send anyway only if you mean to', $e->getMessage());
            }
        });
    }

    #[Test]
    public function an_incomplete_period_can_be_overridden_deliberately(): void
    {
        Storage::fake('local');

        $this->inTenant(function (): void {
            $this->markAllPresent();

            $report = $this->generate(['require_complete' => false]);

            $this->assertNotNull($report->file_path);
            $this->assertSame(1, $report->stats_snapshot['average']['ungraded']);
        });
    }

    #[Test]
    public function the_pdf_is_stored_under_a_tenant_scoped_path(): void
    {
        Storage::fake('local');

        $this->inTenant(function (): void {
            $this->markAllPresent();
            $this->gradeHomework(16);

            $report = $this->generate();

            $this->assertStringStartsWith("reports/tenant-{$report->tenant_id}/", $report->file_path);
            Storage::disk('local')->assertExists($report->file_path);
        });
    }

    #[Test]
    public function internal_notes_never_reach_the_snapshot(): void
    {
        Storage::fake('local');

        $this->inTenant(function (): void {
            $this->markAllPresent();
            $this->gradeHomework(16);

            $this->writeNote('Shown to the family.', visible: true);
            $this->writeNote('Safeguarding context for colleagues only.', visible: false);

            $report = $this->generate();

            $bodies = array_column($report->stats_snapshot['notes'], 'body');

            $this->assertContains('Shown to the family.', $bodies);
            $this->assertNotContains('Safeguarding context for colleagues only.', $bodies);
        });
    }

    #[Test]
    public function a_preview_always_uses_neutral_sample_data(): void
    {
        $this->inTenant(function (): void {
            $template = ReportTemplate::query()->create([
                'name' => 'Default', 'blocks' => config('reporting.default_blocks'), 'is_default' => true,
            ]);

            $html = app(ReportRenderer::class)->preview($template);

            $this->assertStringContainsString('Sample Learner', $html);
            $this->assertStringContainsString('Sample Guardian', $html);
            $this->assertStringNotContainsString($this->learnerName(), $html);
        });
    }

    #[Test]
    public function switching_off_a_block_removes_it_from_the_report(): void
    {
        $this->inTenant(function (): void {
            $renderer = app(ReportRenderer::class);

            $withNotes = ReportTemplate::query()->create([
                'name' => 'With notes', 'blocks' => ['summary', 'highlights', 'work', 'notes'],
            ]);
            $withoutNotes = ReportTemplate::query()->create([
                'name' => 'Without notes', 'blocks' => ['summary', 'work'],
            ]);

            $this->assertStringContainsString('From the teacher', $renderer->preview($withNotes));
            $this->assertStringNotContainsString('From the teacher', $renderer->preview($withoutNotes));
            $this->assertStringNotContainsString('This period', $renderer->preview($withoutNotes));
        });
    }

    private function generate(array $options = []): Report
    {
        $period = app(PeriodService::class)->forLabel($this->batch->load('course'), '2026-08');

        return app(\App\Services\ReportService::class)->generate(
            $this->enrollment->setRelation('batch', $this->batch),
            $period,
            $options,
        );
    }

    private function inTenant(callable $callback): void
    {
        app(TenantContext::class)->runAs($this->tenant, $callback);
    }
}
