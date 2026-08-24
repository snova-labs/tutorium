<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SessionStatus;
use App\Models\AttendanceStatus;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Services\AttendanceService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Attendance vocabulary and a partly-marked month.
 *
 * Deliberately leaves the most recent session unmarked, so the "unmarked sessions" path is
 * visible in development rather than only in a test.
 */
final class AttendanceSeeder extends Seeder
{
    public function run(?Tenant $tenant = null): void
    {
        $tenant ??= app(TenantContext::class)->get();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runAs($tenant, function (): void {
            $statuses = $this->statuses();
            $batch = Batch::query()->orderBy('id')->first();

            if ($batch === null) {
                $this->command?->warn('No batches — run AcademicSeeder first.');

                return;
            }

            $enrollments = Enrollment::query()->where('batch_id', $batch->getKey())->get();

            if ($enrollments->isEmpty()) {
                $this->command?->warn('No enrollments — run PeopleSeeder first.');

                return;
            }

            $sessions = ClassSession::query()
                ->where('batch_id', $batch->getKey())
                ->orderBy('session_local_date')
                ->get();

            $service = app(AttendanceService::class);
            $marked = 0;

            foreach ($sessions->slice(0, max(0, $sessions->count() - 1)) as $index => $session) {
                $marks = $enrollments->values()->map(function (Enrollment $enrollment, int $i) use ($statuses, $index): array {
                    // A realistic spread rather than everyone present: one late, one absent, one
                    // excused across the month, so every branch of the arithmetic has data.
                    $code = match (true) {
                        $i === 1 && $index === 1 => 'LATE',
                        $i === 2 && $index === 2 => 'ABSENT',
                        $i === 3 && $index === 3 => 'EXCUSED',
                        default => 'PRESENT',
                    };

                    return [
                        'enrollment_id' => $enrollment->getKey(),
                        'status_id' => $statuses[$code]->getKey(),
                        'minutes_late' => $code === 'LATE' ? 7 : null,
                        'note' => $code === 'EXCUSED' ? 'Family notice received' : null,
                    ];
                })->all();

                $service->record($session, $marks);
                $marked++;
            }

            ClassSession::query()->where('batch_id', $batch->getKey())
                ->whereIn('id', $sessions->slice(0, $marked)->modelKeys())
                ->update(['status' => SessionStatus::Held]);

            $this->command?->info("Seeded attendance statuses and marked {$marked} sessions "
                .'(the latest is left unmarked on purpose).');
        });
    }

    /** @return array<string, AttendanceStatus> */
    private function statuses(): array
    {
        $defaults = [
            // name, code, attended, in rate, late, negative, colour, sort
            ['Present', 'PRESENT', true, true, false, false, '#16A34A', 0],
            ['Late', 'LATE', true, true, true, false, '#CA8A04', 1],
            ['Absent', 'ABSENT', false, true, false, true, '#DC2626', 2],
            ['Excused', 'EXCUSED', false, false, false, false, '#6B7280', 3],
        ];

        $out = [];

        foreach ($defaults as [$name, $code, $attended, $inRate, $late, $negative, $color, $sort]) {
            $out[$code] = AttendanceStatus::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'counts_as_attended' => $attended,
                'counts_in_rate' => $inRate,
                'is_late' => $late,
                'is_negative' => $negative,
                'color' => $color,
                'sort' => $sort,
            ]);
        }

        return $out;
    }
}
