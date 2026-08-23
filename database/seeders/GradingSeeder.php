<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\GradingSchemeKind;
use App\Models\AssessmentType;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\GradingScheme;
use App\Models\SubmissionStatus;
use App\Models\Tenant;
use App\Models\TypeWeight;
use App\Services\AssessmentService;
use App\Services\GradeBookService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Grading vocabularies, schemes, and a month of mixed-scheme results.
 *
 * Seeds all three scheme kinds in one batch on purpose, so the weighted average is exercised in
 * development rather than only in a test.
 */
final class GradingSeeder extends Seeder
{
    public function run(?Tenant $tenant = null): void
    {
        $tenant ??= app(TenantContext::class)->get();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runAs($tenant, function (): void {
            $statuses = $this->submissionStatuses();
            $types = $this->assessmentTypes();
            $schemes = $this->schemes();

            $batch = Batch::query()->orderBy('id')->first();

            if ($batch === null) {
                $this->command?->warn('No batches — run AcademicSeeder first.');

                return;
            }

            $this->weights($batch, $types);

            $assessments = app(AssessmentService::class);

            $homework = $assessments->publish($assessments->create($batch, [
                'assessment_type_id' => $types['HOMEWORK']->getKey(),
                'grading_scheme_id' => $schemes['POINTS']->getKey(),
                'title' => 'Loops worksheet',
                'assigned_local_date' => '2026-08-08',
                'due_local_date' => '2026-08-15',
                'max_points' => 20,
            ]));

            $project = $assessments->publish($assessments->create($batch, [
                'assessment_type_id' => $types['PROJECT']->getKey(),
                'grading_scheme_id' => $schemes['RUBRIC']->getKey(),
                'title' => 'Robot maze project',
                'assigned_local_date' => '2026-08-08',
                'due_local_date' => '2026-08-22',
            ], [
                ['name' => 'Maze completes without errors', 'max_points' => 15],
                ['name' => 'Code is readable and commented', 'max_points' => 10],
                ['name' => 'Uses a loop rather than repetition', 'max_points' => 10],
                ['name' => 'Explained the approach to the class', 'max_points' => 5],
            ]));

            $quiz = $assessments->publish($assessments->create($batch, [
                'assessment_type_id' => $types['QUIZ']->getKey(),
                'grading_scheme_id' => $schemes['PASSFAIL']->getKey(),
                'title' => 'Vocabulary quiz 3',
                'due_local_date' => '2026-08-19',
            ]));

            $this->grades($batch, $homework, $project, $quiz, $statuses);

            $this->command?->info('Seeded assessment types, six grading schemes, three assessments '
                .'and a month of mixed-scheme results.');
        });
    }

    /** @return array<string, SubmissionStatus> */
    private function submissionStatuses(): array
    {
        $out = [];

        foreach ([
            ['Submitted', 'SUBMITTED', true, false, false, '#16A34A', 0],
            ['Late', 'LATE_SUB', true, false, false, '#CA8A04', 1],
            ['Missing', 'MISSING', false, false, true, '#DC2626', 2],
            ['Exempt', 'EXEMPT', false, true, false, '#6B7280', 3],
        ] as [$name, $code, $submitted, $excluded, $negative, $color, $sort]) {
            $out[$code] = SubmissionStatus::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'counts_as_submitted' => $submitted,
                'excluded_from_average' => $excluded,
                'is_negative' => $negative,
                'color' => $color,
                'sort' => $sort,
            ]);
        }

        return $out;
    }

    /** @return array<string, AssessmentType> */
    private function assessmentTypes(): array
    {
        $out = [];

        foreach ([
            ['Homework', 'HOMEWORK', true, '#334155', 0],
            ['Classwork', 'CLASSWORK', true, '#0F766E', 1],
            ['Project', 'PROJECT', true, '#7C3AED', 2],
            ['Quiz', 'QUIZ', false, '#D97706', 3],
            ['Lab', 'LAB', true, '#059669', 4],
        ] as [$name, $code, $counts, $color, $sort]) {
            $out[$code] = AssessmentType::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'counts_in_submission_rate' => $counts,
                'color' => $color,
                'sort' => $sort,
            ]);
        }

        return $out;
    }

    /** @return array<string, GradingScheme> */
    private function schemes(): array
    {
        $definitions = [
            ['Points', 'POINTS', GradingSchemeKind::Points, []],
            ['Percentage', 'PERCENT', GradingSchemeKind::Percentage, []],
            ['Pass / fail', 'PASSFAIL', GradingSchemeKind::PassFail, ['pass_value' => 100, 'fail_value' => 0]],
            ['Rubric', 'RUBRIC', GradingSchemeKind::Rubric, []],
            ['Letter A–F', 'LETTER_AF', GradingSchemeKind::Letter, ['bands' => [
                ['label' => 'A', 'min' => 85, 'max' => 100],
                ['label' => 'B', 'min' => 70, 'max' => 84],
                ['label' => 'C', 'min' => 55, 'max' => 69],
                ['label' => 'D', 'min' => 40, 'max' => 54],
                ['label' => 'F', 'min' => 0, 'max' => 39],
            ]]],
            // Seeded to make the point that a scale can run backwards without any special case.
            ['German 1–6', 'LETTER_DE', GradingSchemeKind::Letter, ['bands' => [
                ['label' => '1', 'value' => 100], ['label' => '2', 'value' => 80],
                ['label' => '3', 'value' => 65], ['label' => '4', 'value' => 50],
                ['label' => '5', 'value' => 25], ['label' => '6', 'value' => 0],
            ]]],
            ['CEFR', 'CEFR', GradingSchemeKind::Level, ['ladder' => ['A1', 'A2', 'B1', 'B2', 'C1', 'C2']]],
        ];

        $out = [];

        foreach ($definitions as [$name, $code, $kind, $config]) {
            $out[$code] = GradingScheme::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'kind' => $kind, 'config' => $config,
            ]);
        }

        return $out;
    }

    /** @param array<string, AssessmentType> $types */
    private function weights(Batch $batch, array $types): void
    {
        foreach (['HOMEWORK' => 40, 'PROJECT' => 40, 'QUIZ' => 20] as $code => $weight) {
            TypeWeight::query()->firstOrCreate(
                ['course_id' => $batch->course_id, 'assessment_type_id' => $types[$code]->getKey()],
                ['weight_pct' => $weight],
            );
        }
    }

    /** @param array<string, SubmissionStatus> $statuses */
    private function grades(Batch $batch, $homework, $project, $quiz, array $statuses): void
    {
        $enrollments = Enrollment::query()->where('batch_id', $batch->getKey())->get();

        if ($enrollments->isEmpty()) {
            $this->command?->warn('No enrollments — run PeopleSeeder first.');

            return;
        }

        $criteria = $project->rubricCriteria;
        $cells = [];

        foreach ($enrollments->values() as $i => $enrollment) {
            $cells[] = [
                'assessment_id' => $homework->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'submission_status_id' => $statuses[$i === 4 ? 'MISSING' : 'SUBMITTED']->getKey(),
                'raw_score' => $i === 4 ? null : max(10, 20 - $i),
            ];

            // One learner excused, so the rescaling path has data in development.
            $exempt = $i === 5;

            $cells[] = [
                'assessment_id' => $project->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'submission_status_id' => $statuses[$exempt ? 'EXEMPT' : 'SUBMITTED']->getKey(),
                'rubric_scores' => $exempt ? null : $criteria->mapWithKeys(fn ($c, $j) => [
                    $c->getKey() => max(1, (float) $c->max_points - $j),
                ])->all(),
                'feedback' => $exempt ? 'Excused after a family absence.' : null,
            ];

            $cells[] = [
                'assessment_id' => $quiz->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'submission_status_id' => $statuses['SUBMITTED']->getKey(),
                'passed' => $i !== 2,
            ];
        }

        app(GradeBookService::class)->saveGrid($batch, $cells);
    }
}
