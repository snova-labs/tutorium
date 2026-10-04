<?php

declare(strict_types=1);

namespace App\Support\Presets;

use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\EmailTemplate;
use App\Models\EnrollmentStatus;
use App\Models\GradingScheme;
use App\Models\IdSequence;
use App\Models\LearnerStatus;
use App\Models\NoteCategory;
use App\Models\PresetApplication;
use App\Models\RelationType;
use App\Models\ReportTemplate;
use App\Models\SessionType;
use App\Models\Setting;
use App\Models\SubmissionStatus;
use App\Models\Tenant;
use App\Models\TerminologyOverride;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Applies a preset to a tenant.
 *
 * Idempotent by design: everything is matched on its code and created only when missing, so
 * re-applying a newer version adds what is new without overwriting what a customer has since
 * changed. A preset is a starting point, and a starting point that overwrites your work is not
 * one (FR-ONB-1, FR-ONB-5).
 */
final class PresetApplier
{
    public function __construct(
        private readonly PresetRepository $presets,
        private readonly TenantContext $tenancy,
    ) {}

    /** @return array<string, int> what was created, by section */
    public function apply(Tenant $tenant, string $code): array
    {
        $preset = $this->presets->find($code);

        return $this->tenancy->runAs($tenant, function () use ($tenant, $preset, $code): array {
            return DB::transaction(function () use ($tenant, $preset, $code): array {
                $summary = [
                    'terminology' => $this->terminology($preset),
                    'settings' => $this->settings($preset),
                    'sequences' => $this->sequences($preset),
                    'session_types' => $this->sessionTypes($preset),
                    'attendance_statuses' => $this->attendanceStatuses($preset),
                    'submission_statuses' => $this->submissionStatuses($preset),
                    'learner_statuses' => $this->learnerStatuses($preset),
                    'enrollment_statuses' => $this->enrollmentStatuses($preset),
                    'relation_types' => $this->relationTypes($preset),
                    'note_categories' => $this->noteCategories($preset),
                    'assessment_types' => $this->assessmentTypes($preset),
                    'grading_schemes' => $this->gradingSchemes($preset),
                    'report_template' => $this->reportTemplate($preset),
                    'email_templates' => $this->emailTemplates($preset),
                ];

                PresetApplication::query()->create([
                    'preset_code' => $code,
                    'version' => $preset->version(),
                    'summary' => $summary,
                    'applied_by' => Auth::id(),
                    'applied_at' => now(),
                ]);

                $this->tenancy->withoutScoping(fn () => $tenant->update(['preset_code' => $code]));

                return $summary;
            });
        });
    }

    private function terminology(PresetDefinition $preset): int
    {
        $created = 0;

        foreach ($preset->section('terminology') as $key => [$singular, $plural]) {
            $created += TerminologyOverride::query()->firstOrCreate(
                ['term_key' => $key, 'locale' => null],
                ['singular' => $singular, 'plural' => $plural],
            )->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    private function settings(PresetDefinition $preset): int
    {
        $created = 0;
        $values = array_merge($preset->section('modules'), $preset->section('settings'));

        foreach ($values as $key => $value) {
            $created += Setting::query()->firstOrCreate(
                ['key' => $key, 'scope_type' => 'tenant', 'scope_id' => null],
                ['value' => $value],
            )->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    private function sequences(PresetDefinition $preset): int
    {
        $created = 0;

        foreach ($preset->section('sequences') as $entity => $format) {
            $created += IdSequence::query()->firstOrCreate(
                ['entity' => $entity, 'scope_type' => 'tenant', 'scope_id' => null],
                [
                    'prefix' => $format['prefix'],
                    'separator' => $format['separator'],
                    'pad_width' => $format['pad_width'],
                    'next_number' => $format['start'],
                    'is_active' => true,
                ],
            )->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    private function sessionTypes(PresetDefinition $preset): int
    {
        return $this->seed($preset->rows('session_types'), fn (array $row) => [
            SessionType::class,
            ['code' => $row[1]],
            ['name' => $row[0], 'counts_in_attendance' => $row[2]],
        ]);
    }

    private function attendanceStatuses(PresetDefinition $preset): int
    {
        return $this->seed($preset->rows('attendance_statuses'), fn (array $row, int $i) => [
            AttendanceStatus::class,
            ['code' => $row[1]],
            [
                'name' => $row[0], 'counts_as_attended' => $row[2], 'counts_in_rate' => $row[3],
                'is_late' => $row[4], 'is_negative' => $row[5], 'sort' => $i,
            ],
        ]);
    }

    private function submissionStatuses(PresetDefinition $preset): int
    {
        return $this->seed($preset->rows('submission_statuses'), fn (array $row, int $i) => [
            SubmissionStatus::class,
            ['code' => $row[1]],
            [
                'name' => $row[0], 'counts_as_submitted' => $row[2],
                'excluded_from_average' => $row[3], 'is_negative' => $row[4], 'sort' => $i,
            ],
        ]);
    }

    private function learnerStatuses(PresetDefinition $preset): int
    {
        return $this->seed($preset->rows('learner_statuses'), fn (array $row, int $i) => [
            LearnerStatus::class,
            ['code' => $row[1]],
            ['name' => $row[0], 'is_terminal' => $row[2], 'sort' => $i],
        ]);
    }

    private function enrollmentStatuses(PresetDefinition $preset): int
    {
        return $this->seed($preset->rows('enrollment_statuses'), fn (array $row, int $i) => [
            EnrollmentStatus::class,
            ['code' => $row[1]],
            [
                'name' => $row[0], 'is_active_for_billing' => $row[2],
                'is_terminal' => $row[3], 'sort' => $i,
            ],
        ]);
    }

    private function relationTypes(PresetDefinition $preset): int
    {
        return $this->seed($preset->rows('relation_types'), fn (array $row, int $i) => [
            RelationType::class, ['code' => $row[1]], ['name' => $row[0], 'sort' => $i],
        ]);
    }

    private function noteCategories(PresetDefinition $preset): int
    {
        return $this->seed($preset->rows('note_categories'), fn (array $row, int $i) => [
            NoteCategory::class,
            ['code' => $row[1]],
            ['name' => $row[0], 'report_visible_default' => $row[2], 'sort' => $i],
        ]);
    }

    private function assessmentTypes(PresetDefinition $preset): int
    {
        return $this->seed($preset->rows('assessment_types'), fn (array $row, int $i) => [
            AssessmentType::class,
            ['code' => $row[1]],
            ['name' => $row[0], 'counts_in_submission_rate' => $row[2], 'sort' => $i],
        ]);
    }

    private function gradingSchemes(PresetDefinition $preset): int
    {
        return $this->seed($preset->rows('grading_schemes'), fn (array $row) => [
            GradingScheme::class,
            ['code' => $row[1]],
            ['name' => $row[0], 'kind' => $row[2], 'config' => $row[3], 'is_active' => true],
        ]);
    }

    private function reportTemplate(PresetDefinition $preset): int
    {
        $report = $preset->section('report');

        return ReportTemplate::query()->firstOrCreate(
            ['name' => 'Default report'],
            [
                'blocks' => $report['blocks'] ?? config('reporting.default_blocks'),
                'closing' => $report['closing'] ?? null,
                'is_default' => true,
            ],
        )->wasRecentlyCreated ? 1 : 0;
    }

    private function emailTemplates(PresetDefinition $preset): int
    {
        $created = 0;

        foreach ($preset->section('email_templates') as $code => $template) {
            $created += EmailTemplate::query()->firstOrCreate(
                ['code' => $code, 'brand_id' => null],
                ['subject' => $template['subject'], 'body_html' => $template['body']],
            )->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param callable(array<int, mixed>, int): array{0: class-string, 1: array<string, mixed>, 2: array<string, mixed>} $mapper
     */
    private function seed(array $rows, callable $mapper): int
    {
        $created = 0;

        foreach ($rows as $i => $row) {
            [$model, $match, $attributes] = $mapper($row, $i);
            $created += $model::query()->firstOrCreate($match, $attributes)->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }
}
