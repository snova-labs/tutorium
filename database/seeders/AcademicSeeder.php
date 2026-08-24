<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BatchStatus;
use App\Enums\DeliveryMode;
use App\Enums\PeriodType;
use App\Enums\Weekday;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Course;
use App\Models\SessionType;
use App\Models\Tenant;
use App\Models\TimetableSlot;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\SessionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Academic structure for development.
 *
 * Two batches on purpose: one in Kathmandu (+05:45, no daylight saving) and one in Toronto
 * (daylight saving, and a different week structure). A single-timezone development database
 * hides exactly the class of bug that costs the most to find later.
 */
final class AcademicSeeder extends Seeder
{
    public function run(?Tenant $tenant = null): void
    {
        $tenant ??= app(TenantContext::class)->get();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runAs($tenant, function (): void {
            $types = $this->sessionTypes();
            $branches = Branch::query()->get()->keyBy('code');

            if ($branches->isEmpty()) {
                $this->command?->warn('No branches — run DatabaseSeeder first.');

                return;
            }

            $kids = Course::query()->firstOrCreate(
                ['code' => 'KIDS-CS'],
                [
                    'brand_id' => $branches->first()->brand_id,
                    'name' => 'Kids Computer Science',
                    'audience' => 'kids',
                    'period_type' => PeriodType::Monthly,
                    'description' => 'Weekly class plus a midweek lab.',
                    'is_active' => true,
                ],
            );

            if ($branches->has('KTM')) {
                $this->batch(
                    $kids,
                    $branches['KTM'],
                    'Saturday Kids — Aug 2026',
                    'SAT-KIDS-AUG26',
                    'Asia/Kathmandu',
                    [[Weekday::Saturday, '10:00:00', 120, $types['CLASS']], [Weekday::Wednesday, '17:00:00', 60, $types['LAB']]],
                );
            }

            if ($branches->has('ONL')) {
                $this->batch(
                    $kids,
                    $branches['ONL'],
                    'Kids CS — Online Americas',
                    'ONL-KIDS-AUG26',
                    'America/Toronto',
                    [[Weekday::Saturday, '09:00:00', 120, $types['CLASS']]],
                    DeliveryMode::Online,
                );
            }

            $this->command?->info('Seeded courses, batches, timetables and August sessions.');
        });
    }

    /** @return array<string, int> */
    private function sessionTypes(): array
    {
        $defaults = [
            ['Class', 'CLASS', true, '#334155', 0],
            ['Lab', 'LAB', true, '#0F766E', 1],
            ['Workshop', 'WORKSHOP', true, '#7C3AED', 2],
            // Recorded, but deliberately outside the attendance percentage: a make-up class must
            // not inflate a term's figure.
            ['Make-up', 'MAKEUP', false, '#D97706', 3],
        ];

        $ids = [];

        foreach ($defaults as [$name, $code, $counts, $color, $sort]) {
            $ids[$code] = SessionType::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'counts_in_attendance' => $counts, 'color' => $color, 'sort' => $sort],
            )->getKey();
        }

        return $ids;
    }

    /** @param array<int, array{0: Weekday, 1: string, 2: int, 3: int}> $slots */
    private function batch(
        Course $course,
        Branch $branch,
        string $name,
        string $code,
        string $timezone,
        array $slots,
        DeliveryMode $mode = DeliveryMode::InPerson,
    ): void {
        $batch = Batch::query()->firstOrCreate(
            ['code' => $code],
            [
                'course_id' => $course->getKey(),
                'branch_id' => $branch->getKey(),
                'name' => $name,
                'timezone' => $timezone,
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-12-31',
                'capacity' => 30,
                'delivery_mode' => $mode,
                'status' => BatchStatus::Running,
            ],
        );

        foreach ($slots as [$weekday, $time, $duration, $typeId]) {
            TimetableSlot::query()->firstOrCreate(
                ['batch_id' => $batch->getKey(), 'weekday' => $weekday->value, 'start_time_local' => $time],
                ['session_type_id' => $typeId, 'duration_min' => $duration],
            );
        }

        app(SessionGenerator::class)->generate(
            $batch->refresh(),
            CarbonImmutable::parse('2026-08-01', $timezone),
            CarbonImmutable::parse('2026-08-31', $timezone),
        );
    }
}
