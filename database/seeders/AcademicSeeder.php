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
 * Two batches on purpose: one at the account's own location, and one online in America/Toronto.
 * A single-timezone development database hides exactly the class of bug that costs the most to
 * find later, so the second branch is created here if the provisioner did not make one — it
 * provisions a single location in the account's own timezone (TenantProvisioner), which is
 * correct for a real signup and insufficient for development.
 */
final class AcademicSeeder extends Seeder
{
    /** Where the online cohort sits. Chosen for daylight saving, not for the city. */
    private const ONLINE_TIMEZONE = 'America/Toronto';

    public function run(?Tenant $tenant = null): void
    {
        $tenant ??= app(TenantContext::class)->get();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runAs($tenant, function (): void {
            $types = $this->sessionTypes();

            $home = Branch::query()->orderBy('id')->first();

            if ($home === null) {
                $this->command?->warn('No branches — the account was not provisioned properly.');

                return;
            }

            $kids = Course::query()->firstOrCreate(
                ['code' => 'KIDS-CS'],
                [
                    'brand_id' => $home->brand_id,
                    'name' => 'Kids Computer Science',
                    'audience' => 'kids',
                    'period_type' => PeriodType::Monthly,
                    'description' => 'Weekly class plus a midweek lab.',
                    'is_active' => true,
                ],
            );

            $created = 0;

            // The account's own location, on its own clock — whatever the provisioner set.
            $created += $this->batch(
                $kids,
                $home,
                'Saturday Kids — Aug 2026',
                'SAT-KIDS-AUG26',
                $home->timezone,
                [
                    [Weekday::Saturday, '10:00:00', 120, $types['CLASS']],
                    [Weekday::Wednesday, '17:00:00', 60, $types['LAB']],
                ],
            );

            $created += $this->batch(
                $kids,
                $this->onlineBranch($home),
                'Kids CS — Online Americas',
                'ONL-KIDS-AUG26',
                self::ONLINE_TIMEZONE,
                [[Weekday::Saturday, '09:00:00', 120, $types['CLASS']]],
                DeliveryMode::Online,
            );

            if ($created === 0) {
                $this->command?->warn('No batches were created — nothing downstream will have data.');

                return;
            }

            $this->command?->info("Seeded courses, {$created} batches, timetables and August sessions.");
        });
    }

    /**
     * The online branch, created here rather than by the provisioner.
     *
     * A real signup gets one location in one timezone, which is right. Development needs a
     * daylight-saving clock in the database from the first `migrate:fresh` or the DST paths are
     * only ever exercised by the test suite.
     */
    private function onlineBranch(Branch $home): Branch
    {
        return Branch::query()->firstOrCreate(
            ['code' => 'ONL'],
            [
                'brand_id' => $home->brand_id,
                'name' => 'Online — Americas',
                'timezone' => self::ONLINE_TIMEZONE,
                'week_start' => 'sunday',
                'weekend_days' => ['saturday', 'sunday'],
                'is_active' => true,
            ],
        );
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

    /**
     * @param array<int, array{0: Weekday, 1: string, 2: int, 3: int}> $slots
     * @return int 1 if a batch now exists, 0 if it could not be created
     */
    private function batch(
        Course $course,
        Branch $branch,
        string $name,
        string $code,
        string $timezone,
        array $slots,
        DeliveryMode $mode = DeliveryMode::InPerson,
    ): int {
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

        return 1;
    }
}