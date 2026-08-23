<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\EmailTemplate;
use App\Models\Enrollment;
use App\Models\NoteCategory;
use App\Models\ReportTemplate;
use App\Models\Tenant;
use App\Services\TeacherNoteService;
use App\Support\Sequences\IdSequenceService;
use App\Models\IdSequence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class ReportingSeeder extends Seeder
{
    public function run(?Tenant $tenant = null): void
    {
        $tenant ??= app(TenantContext::class)->get();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runAs($tenant, function (): void {
            $this->categories();
            $this->sequences();
            $this->templates();
            $this->emailTemplate();
            $this->notes();

            $this->command?->info('Seeded note categories, a report template, an email template and notes.');
        });
    }

    private function categories(): void
    {
        foreach ([
            // name, code, shown on reports by default, colour, sort
            ['General', 'GENERAL', true, '#334155', 0],
            ['Academic', 'ACADEMIC', true, '#0F766E', 1],
            ['Participation', 'PARTICIPATION', true, '#7C3AED', 2],
            ['Attendance', 'ATTENDANCE', false, '#CA8A04', 3],
            // Off by default on purpose: a behaviour note is context for colleagues first.
            ['Behaviour', 'BEHAVIOUR', false, '#DC2626', 4],
        ] as [$name, $code, $visible, $color, $sort]) {
            NoteCategory::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'report_visible_default' => $visible,
                'color' => $color,
                'sort' => $sort,
            ]);
        }
    }

    private function sequences(): void
    {
        IdSequence::query()->firstOrCreate(
            ['entity' => 'report', 'scope_type' => 'tenant', 'scope_id' => null],
            ['prefix' => 'RPT', 'separator' => '-', 'pad_width' => 5, 'next_number' => 1, 'is_active' => true],
        );
    }

    private function templates(): void
    {
        ReportTemplate::query()->firstOrCreate(
            ['name' => 'Default report'],
            [
                'blocks' => config('reporting.default_blocks'),
                'closing' => 'Warm regards, the teaching team',
                'is_default' => true,
            ],
        );

        // A second template with a different shape, so the block toggles are visible in
        // development rather than only in a test.
        ReportTemplate::query()->firstOrCreate(
            ['name' => 'Certification report'],
            ['blocks' => ['summary', 'work'], 'closing' => 'Congratulations on completing this module.'],
        );
    }

    private function emailTemplate(): void
    {
        EmailTemplate::query()->firstOrCreate(
            ['code' => 'report_delivery', 'brand_id' => null],
            [
                'subject' => '{brand_name} — progress report for {period}',
                'body_html' => '<p>Dear {recipient_name},</p>'
                    .'<p>The progress report for <strong>{learner_name}</strong> in {batch_name}, '
                    .'covering {period}, is attached.</p>'
                    .'<p>If anything in it raises a question, reply to this message and it will reach us directly.</p>'
                    .'<p>{brand_name}</p>',
                'variables' => ['recipient_name', 'learner_name', 'period', 'brand_name', 'batch_name'],
            ],
        );
    }

    private function notes(): void
    {
        $batch = Batch::query()->orderBy('id')->first();

        if ($batch === null) {
            return;
        }

        $general = NoteCategory::query()->where('code', 'GENERAL')->first();
        $behaviour = NoteCategory::query()->where('code', 'BEHAVIOUR')->first();
        $service = app(TeacherNoteService::class);

        foreach (Enrollment::query()->where('batch_id', $batch->getKey())->take(4)->get() as $i => $enrollment) {
            $service->write($enrollment->load('batch.course'), [
                'note_category_id' => $general->getKey(),
                'body' => 'Worked steadily this period and asked good questions in class. '
                    .'A regular routine at home would help consolidate the progress.',
                'period' => '2026-08',
            ]);

            // One internal note, so the visibility rule can be seen working.
            if ($i === 0) {
                $service->write($enrollment->load('batch.course'), [
                    'note_category_id' => $behaviour->getKey(),
                    'body' => 'Context for colleagues: a family absence explains the missed sessions. '
                        .'Not for the report.',
                    'period' => '2026-08',
                ]);
            }
        }
    }
}
