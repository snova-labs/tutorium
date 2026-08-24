<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Models\ReportTemplate;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Turns a snapshot and a template into HTML.
 *
 * Reads the snapshot rather than the live models on purpose: rendering a report a second time
 * must produce exactly what was sent the first time, even if a grade has been corrected since.
 */
final class ReportRenderer
{
    public function __construct(private readonly ViewFactory $views) {}

    /** @param array<string, mixed> $snapshot */
    public function render(
        array $snapshot,
        ReportTemplate $template,
        string $recipientName,
        string $reportNumber,
        string $accent = '#1B5FAA',
    ): string {
        return $this->views->make('reports.default', [
            'snapshot' => $snapshot,
            'template' => $template,
            'recipientName' => $recipientName,
            'reportNumber' => $reportNumber,
            'accent' => $accent,
            'locale' => $template->locale ?? 'en',
            'termFor' => [],
        ])->render();
    }

    /**
     * A preview built from neutral sample data.
     *
     * Never uses a real learner, in a preview, a demo or a screenshot — the name a template author
     * sees while they work should not be a child's (FR-RPT-2).
     */
    public function preview(ReportTemplate $template, string $accent = '#1B5FAA'): string
    {
        return $this->render($this->sampleSnapshot(), $template, 'Sample Guardian', 'RPT-00000', $accent);
    }

    /** @return array<string, mixed> */
    public function sampleSnapshot(): array
    {
        return [
            'learner_name' => 'Sample Learner',
            'batch_name' => 'Sample Class',
            'course_name' => 'Sample Course',
            'brand_name' => 'Sample Brand',
            'period' => ['label' => '2026-08', 'starts' => '2026-08-01', 'ends' => '2026-08-31'],
            'attendance' => [
                'attended' => 7, 'counted' => 8, 'percentage' => 87.5, 'unmarked' => 0,
                'excused' => 1, 'made_up' => 0, 'is_complete' => true, 'is_informational' => false,
            ],
            'average' => [
                'percentage' => 86.0, 'is_weighted' => true, 'weights_used' => 100.0, 'breakdown' => [],
                'graded' => 3, 'missing' => 0, 'excluded' => 1, 'ungraded' => 0,
                'is_complete' => true, 'explanation' => 'Weighted average across assessment types.',
            ],
            'assessments' => [
                ['title' => 'Sample worksheet', 'type' => 'Homework', 'due' => '2026-08-15',
                    'result' => '16 / 20', 'status' => 'Submitted', 'normalized_pct' => 80.0, 'feedback' => null],
                ['title' => 'Sample project', 'type' => 'Project', 'due' => '2026-08-22',
                    'result' => '34 / 40', 'status' => 'Submitted', 'normalized_pct' => 85.0, 'feedback' => null],
                ['title' => 'Sample quiz', 'type' => 'Quiz', 'due' => '2026-08-19',
                    'result' => 'Pass', 'status' => 'Submitted', 'normalized_pct' => 100.0, 'feedback' => null],
            ],
            'highlights' => [
                'Did particularly well on Sample project (34 / 40).',
                'One piece of work was excused this period and is not counted in the average.',
            ],
            'notes' => [[
                'category' => 'General',
                'body' => 'Worked hard this period and asked good questions. A regular routine would '
                    .'help consolidate the progress.',
            ]],
            'engagement_stars' => 4,
            'generated_at_utc' => now()->toIso8601String(),
        ];
    }
}
