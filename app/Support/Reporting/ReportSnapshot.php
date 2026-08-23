<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Everything a report says, frozen at the moment it was generated.
 *
 * Stored on the report row and read back verbatim. Nothing re-derives these figures later, which
 * is what makes a sent report evidence rather than a view (FR-RPT-5).
 *
 * @implements Arrayable<string, mixed>
 */
final class ReportSnapshot implements Arrayable
{
    /**
     * @param  array<string, mixed>  $attendance
     * @param  array<string, mixed>  $average
     * @param  array<int, array<string, mixed>>  $assessments
     * @param  array<int, string>  $highlights
     * @param  array<int, array{category: string, body: string}>  $notes
     */
    public function __construct(
        public readonly string $learnerName,
        public readonly string $batchName,
        public readonly string $courseName,
        public readonly string $brandName,
        public readonly string $periodLabel,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly array $attendance,
        public readonly array $average,
        public readonly array $assessments,
        public readonly array $highlights,
        public readonly array $notes,
        public readonly ?int $engagementStars,
        public readonly string $generatedAtUtc,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'learner_name' => $this->learnerName,
            'batch_name' => $this->batchName,
            'course_name' => $this->courseName,
            'brand_name' => $this->brandName,
            'period' => [
                'label' => $this->periodLabel,
                'starts' => $this->periodStart,
                'ends' => $this->periodEnd,
            ],
            'attendance' => $this->attendance,
            'average' => $this->average,
            'assessments' => $this->assessments,
            'highlights' => $this->highlights,
            'notes' => $this->notes,
            'engagement_stars' => $this->engagementStars,
            'generated_at_utc' => $this->generatedAtUtc,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            learnerName: $data['learner_name'],
            batchName: $data['batch_name'],
            courseName: $data['course_name'],
            brandName: $data['brand_name'],
            periodLabel: $data['period']['label'],
            periodStart: $data['period']['starts'],
            periodEnd: $data['period']['ends'],
            attendance: $data['attendance'],
            average: $data['average'],
            assessments: $data['assessments'],
            highlights: $data['highlights'],
            notes: $data['notes'],
            engagementStars: $data['engagement_stars'] ?? null,
            generatedAtUtc: $data['generated_at_utc'],
        );
    }
}
