<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\EnrollmentStatus;
use App\Models\IdSequence;
use App\Models\LearnerStatus;
use App\Models\RelationType;
use App\Models\Tenant;
use App\Services\EnrollmentService;
use App\Services\GuardianService;
use App\Services\LearnerService;
use App\Support\Tenancy\TenantContext;

/**
 * Vocabularies and a sample roster.
 *
 * The status sets seeded here are examples a tenant edits, not a fixed taxonomy — which is why
 * `is_active_for_billing` sits on the row rather than in code.
 */
final class PeopleSeeder extends ConsoleAwareSeeder
{
    public function run(?Tenant $tenant = null): void
    {
        $tenant ??= app(TenantContext::class)->get();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->runAs($tenant, function (): void {
            $this->vocabularies();
            $this->roster();
        });
    }

    private function vocabularies(): void
    {
        foreach ([
            ['Prospect', 'PROSPECT', false, 0],
            ['Active', 'ACTIVE', false, 1],
            ['On hold', 'ON_HOLD', false, 2],
            ['Completed', 'COMPLETED', true, 3],
            ['Withdrawn', 'WITHDRAWN', true, 4],
        ] as [$name, $code, $terminal, $sort]) {
            LearnerStatus::query()->firstOrCreate(['code' => $code],
                ['name' => $name, 'is_terminal' => $terminal, 'sort' => $sort]);
        }

        foreach ([
            // name, code, counts toward the bill, terminal, sort
            ['Active', 'ACTIVE', true, false, 0],
            ['On hold', 'ON_HOLD', false, false, 1],
            ['Completed', 'COMPLETED', false, true, 2],
            ['Transferred', 'TRANSFERRED', false, true, 3],
            ['Withdrawn', 'WITHDRAWN', false, true, 4],
        ] as [$name, $code, $billing, $terminal, $sort]) {
            EnrollmentStatus::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'is_active_for_billing' => $billing,
                'is_terminal' => $terminal,
                'sort' => $sort,
            ]);
        }

        foreach ([['Mother', 'MOTHER'], ['Father', 'FATHER'], ['Guardian', 'GUARDIAN'], ['Sponsor', 'SPONSOR']] as $i => [$name, $code]) {
            RelationType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'sort' => $i]);
        }

        IdSequence::query()->firstOrCreate(
            ['entity' => 'enrollment', 'scope_type' => 'tenant', 'scope_id' => null],
            ['prefix' => 'ENR', 'separator' => '-', 'pad_width' => 4, 'next_number' => 1, 'is_active' => true],
        );
    }

    private function roster(): void
    {
        $batch = Batch::query()->orderBy('id')->first();

        if ($batch === null) {
            $this->say('warn', 'No batches — run AcademicSeeder first.');

            return;
        }

        $learners = app(LearnerService::class);
        $guardians = app(GuardianService::class);
        $enrollments = app(EnrollmentService::class);

        // One guardian with two children, so the sibling case is exercised in development rather
        // than discovered in production.
        $shared = ['name' => 'Sample Guardian A', 'email' => 'guardian.a@example.test', 'phone' => '+977 9800000001'];

        foreach (range(1, 8) as $i) {
            $learner = $learners->create([
                'legal_name' => 'Sample Learner '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'date_of_birth' => '2014-0'.max(1, $i % 9).'-15',
                'country' => 'NP',
            ]);

            $guardians->attachOrCreate(
                $learner,
                $i <= 2 ? $shared : [
                    'name' => 'Sample Guardian '.chr(64 + $i),
                    'email' => 'guardian.'.strtolower(chr(64 + $i)).'@example.test',
                ],
                isPrimary: true,
            );

            $enrollments->enroll($learner, $batch);
        }

        $this->say('info', 'Seeded 8 learners, 7 guardians (two siblings share one) and 8 enrollments.');
    }
}
