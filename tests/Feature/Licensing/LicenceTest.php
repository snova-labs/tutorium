<?php

declare(strict_types=1);

namespace Tests\Feature\Licensing;

use App\Models\AttendanceRecord;
use App\Models\AttendanceStatus;
use App\Models\Batch;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Learner;
use App\Models\LearnerStatus;
use App\Models\Tenant;
use App\Services\AttendanceService;
use App\Services\EnrollmentService;
use App\Services\EntitlementService;
use App\Services\LicenceService;
use App\Services\TenantProvisioner;
use App\Support\Billing\Entitlement;
use App\Support\Billing\EntitlementSource;
use App\Support\Diagnostics\CheckResult;
use App\Support\Diagnostics\PlatformVerifier;
use App\Support\Licensing\LicenceFile;
use App\Support\Licensing\LicenceProblem;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A self-hosted install, told what it may do by a file and nothing else.
 *
 * Two promises are tested here, and they are the two the product is sold on:
 *  - Nothing phones home. A licence is a signed file, checked with local arithmetic against a key
 *    shipped in the release, so an install behind an air gap behaves exactly like one next to us
 *    (NFR-PORT-2).
 *  - A full cap, an expired licence and a lapsed one all refuse the same single thing: enrolling
 *    somebody new. Attendance for learners who are already there never stops, whatever the file
 *    says, because a licensing disagreement is not a reason to take the register off a teacher
 *    mid-lesson (FR-ENT-3).
 */
final class LicenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Batch $batchA;

    private Batch $batchB;

    private string $publicKey;

    private string $secretKey;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->publicKey, $this->secretKey] = LicenceFile::keypair();
        $this->path = storage_path('framework/testing/licence-'.bin2hex(random_bytes(6)).'.json');

        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0o777, true);
        }

        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Sunrise Academy',
            'owner_name' => 'Sunrise Owner',
            'owner_email' => 'owner@sunrise.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
        ])['tenant'];

        $this->inTenant(function (): void {
            $course = Course::factory()->create(['brand_id' => Brand::query()->first()->getKey()]);
            $branch = Branch::query()->first();

            $this->batchA = Batch::factory()->create([
                'course_id' => $course->getKey(), 'branch_id' => $branch->getKey(),
                'code' => 'BATCH-A', 'capacity' => null,
            ]);
            $this->batchB = Batch::factory()->create([
                'course_id' => $course->getKey(), 'branch_id' => $branch->getKey(),
                'code' => 'BATCH-B', 'capacity' => null,
            ]);
        });
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_licence_is_checked_here_rather_than_asked_about(): void
    {
        // Any outbound request at all fails this test. Validation is local arithmetic over a key
        // shipped with the release; there is no licence server to be unreachable.
        Http::preventStrayRequests();
        Http::fake();

        $this->install(['max_learners' => 500, 'features' => ['sso' => true]]);

        $licence = app(LicenceService::class)->licence();

        $this->assertNotNull($licence);
        $this->assertSame('Sunrise Academy', $licence->licensee);
        $this->assertSame(500, $licence->maxLearners);

        $entitlements = app(EntitlementService::class);
        $this->assertSame('licence', $entitlements->sourceName());
        $this->assertTrue($entitlements->allows('sso', $this->tenant));
        $this->assertSame(500, $entitlements->limit('max_learners', $this->tenant));
        $this->assertSame(
            Entitlement::SOURCE_LICENCE,
            $entitlements->get('max_learners', $this->tenant)->source,
        );

        Http::assertNothingSent();
    }

    #[Test]
    public function a_cap_somebody_raised_themselves_is_refused(): void
    {
        // The whole point of the signature: the file is readable, editable, and worthless once
        // edited. Raising the cap by hand must not raise the cap.
        $this->install(['max_learners' => 50]);

        $envelope = json_decode((string) file_get_contents($this->path), true);
        $claims = json_decode((string) base64_decode((string) $envelope['payload'], true), true);
        $claims['max_learners'] = 50_000;
        $envelope['payload'] = base64_encode((string) json_encode($claims));
        file_put_contents($this->path, (string) json_encode($envelope));

        $this->forgetLicence();

        $this->assertNull(app(LicenceService::class)->licence());
        $this->assertStringContainsString('changed since it was issued', (string) app(LicenceService::class)->problem());
    }

    #[Test]
    public function a_licence_signed_by_somebody_else_is_not_a_licence(): void
    {
        [, $theirSecret] = LicenceFile::keypair();

        $this->install(['max_learners' => 10], signWith: $theirSecret);

        $this->assertNull(app(LicenceService::class)->licence());
        $this->assertStringContainsString('signature', (string) app(LicenceService::class)->problem());
    }

    #[Test]
    public function a_build_with_no_key_trusts_nothing_rather_than_everything(): void
    {
        $this->install(['max_learners' => 10]);
        config(['licence.public_key' => '']);
        $this->forgetLicence();

        $this->assertNull(app(LicenceService::class)->licence());
        $this->assertStringContainsString('LICENCE_PUBLIC_KEY', (string) app(LicenceService::class)->problem());
    }

    #[Test]
    public function a_file_that_is_missing_or_not_a_licence_says_what_to_do_about_it(): void
    {
        $this->selfHosted();

        $this->assertStringContainsString('No licence file at', (string) app(LicenceService::class)->problem());

        file_put_contents($this->path, 'this is not JSON');
        $this->forgetLicence();
        $this->assertStringContainsString('not JSON', (string) app(LicenceService::class)->problem());

        file_put_contents($this->path, (string) json_encode(['format' => 99, 'payload' => '', 'signature' => '']));
        $this->forgetLicence();
        $this->assertStringContainsString('format 99', (string) app(LicenceService::class)->problem());

        $this->expectException(LicenceProblem::class);
        LicenceFile::read($this->path.'-nowhere', $this->publicKey);
    }

    #[Test]
    public function a_full_cap_blocks_a_new_learner_and_leaves_the_register_alone(): void
    {
        // The acceptance criterion, in one test: "cap warns and blocks new enrollments without
        // stopping attendance for existing learners".
        $this->install(['max_learners' => 1]);

        $this->inTenant(function (): void {
            $first = $this->learner('Already Here');
            $enrollment = app(EnrollmentService::class)->enroll($first, $this->batchA);

            // Somebody already counted can still be put into another class: they were paid for.
            app(EnrollmentService::class)->enroll($first, $this->batchB);

            try {
                app(EnrollmentService::class)->enroll($this->learner('One Too Many'), $this->batchA);
                $this->fail('A full licence cap should refuse a new learner.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('covers 1', $e->getMessage());
            }

            // And the register for the learner who is already there still saves.
            $session = ClassSession::factory()->held()->create(['batch_id' => $this->batchA->getKey()]);
            $present = AttendanceStatus::query()->where('code', 'PRESENT')->firstOrFail();

            app(AttendanceService::class)->record($session, [
                ['enrollment_id' => $enrollment->getKey(), 'status_id' => $present->getKey()],
            ]);

            $this->assertSame(1, AttendanceRecord::query()->where('class_session_id', $session->getKey())->count());
        });

        $status = app(LicenceService::class)->status();

        $this->assertSame(1, $status['learners_now']);
        $this->assertSame(0, $status['places_left']);
        $this->assertStringContainsString('All 1 learner places', (string) $status['warning']);
    }

    #[Test]
    public function the_warning_arrives_while_there_is_still_room(): void
    {
        $this->install(['max_learners' => 10]);

        $this->inTenant(function (): void {
            for ($i = 1; $i <= 9; $i++) {
                app(EnrollmentService::class)->enroll($this->learner(sprintf('Learner %02d', $i)), $this->batchA);
            }
        });

        $status = app(LicenceService::class)->status();

        // Nine of ten: still room for one, and already being said out loud.
        $this->assertTrue($status['ok']);
        $this->assertSame(1, $status['places_left']);
        $this->assertSame('9 of the 10 learner places on the licence are in use.', $status['warning']);
    }

    #[Test]
    public function an_expired_licence_keeps_working_through_its_grace_period(): void
    {
        $this->install([
            'max_learners' => 100,
            'issued_on' => '2025-10-01',
            'expires_on' => CarbonImmutable::now()->subDays(3)->toDateString(),
        ]);

        $status = app(LicenceService::class)->status();

        $this->assertTrue($status['ok'], 'A renewal three days late is not an outage.');
        $this->assertTrue($status['licence']['in_grace']);
        $this->assertStringContainsString('keeps working until', (string) $status['warning']);

        // And a new learner can still be enrolled during the grace period.
        $this->inTenant(fn () => app(EnrollmentService::class)->enroll($this->learner('Mid Renewal'), $this->batchA));

        $this->assertSame(1, Enrollment::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_lapsed_licence_stops_new_enrollments_and_nothing_else(): void
    {
        $this->install([
            'max_learners' => 100,
            'issued_on' => '2024-10-01',
            'expires_on' => CarbonImmutable::now()->subDays(120)->toDateString(),
        ]);

        $this->inTenant(function (): void {
            // Enrolled before the licence lapsed, as far as the product is concerned.
            $learner = $this->learner('Enrolled Last Year');
            $enrollment = Enrollment::query()->create([
                'learner_id' => $learner->getKey(),
                'batch_id' => $this->batchA->getKey(),
                'number' => 'ENR-OLD-1',
                'enrolled_on' => CarbonImmutable::now()->subMonths(6)->toDateString(),
                'status_id' => EnrollmentStatus::query()->where('code', 'ACTIVE')->firstOrFail()->getKey(),
            ]);

            try {
                app(EnrollmentService::class)->enroll($this->learner('Brand New'), $this->batchA);
                $this->fail('A lapsed licence should refuse a new enrollment.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('grace period ended', $e->getMessage());
            }

            // Attendance for the learner who is already there is untouched.
            $session = ClassSession::factory()->held()->create(['batch_id' => $this->batchA->getKey()]);
            $present = AttendanceStatus::query()->where('code', 'PRESENT')->firstOrFail();

            $saved = app(AttendanceService::class)->record($session, [
                ['enrollment_id' => $enrollment->getKey(), 'status_id' => $present->getKey()],
            ]);

            $this->assertSame(1, $saved['saved']);
        });

        $status = app(LicenceService::class)->status();

        $this->assertFalse($status['ok']);
        $this->assertStringContainsString('attendance, grades, reports and exports all still work', (string) $status['warning']);
    }

    #[Test]
    public function a_deploy_is_told_when_the_licence_is_the_problem(): void
    {
        $this->selfHosted();

        $licence = $this->check(app(PlatformVerifier::class)->run());
        $this->assertNotNull($licence, 'A self-hosted install should be checked for a licence.');
        $this->assertTrue($licence->failed());

        $this->install([
            'max_learners' => 100,
            'expires_on' => CarbonImmutable::now()->addDays(10)->toDateString(),
        ]);

        $soon = $this->check(app(PlatformVerifier::class)->run());
        $this->assertSame(CheckResult::WARN, $soon->status);
        $this->assertStringContainsString('runs out on', $soon->detail);
    }

    #[Test]
    public function cloud_mode_has_no_licence_to_talk_about(): void
    {
        // The same code, the same product. Nothing above happens to a hosted academy.
        $this->assertNull(app(LicenceService::class)->status());
        $this->assertNull(app(LicenceService::class)->warning());
        $this->assertNull($this->check(app(PlatformVerifier::class)->run()));
        $this->assertSame('subscription', app(EntitlementService::class)->sourceName());

        $this->inTenant(fn () => app(EnrollmentService::class)->enroll($this->learner('Hosted Learner'), $this->batchA));

        $this->assertSame(1, Enrollment::query()->withoutGlobalScopes()->count());
    }

    /**
     * Install a signed licence and switch the product to self-hosted mode.
     *
     * @param array<string, mixed> $claims
     */
    private function install(array $claims, ?string $signWith = null): void
    {
        $claims += [
            'id' => 'LIC-2026-0001',
            'licensee' => 'Sunrise Academy',
            'issued_on' => CarbonImmutable::now()->subMonth()->toDateString(),
            'expires_on' => CarbonImmutable::now()->addYear()->toDateString(),
        ];

        file_put_contents($this->path, LicenceFile::sign($claims, $signWith ?? $this->secretKey));

        $this->selfHosted();
    }

    private function selfHosted(): void
    {
        config([
            'platform.mode' => 'self_hosted',
            'licence.path' => $this->path,
            'licence.public_key' => $this->publicKey,
            'licence.grace_days' => 30,
        ]);

        $this->forgetLicence();
    }

    /** The licence is read once per request, so a test that changes the file says so. */
    private function forgetLicence(): void
    {
        $this->app->forgetInstance(LicenceService::class);
        $this->app->forgetInstance(EntitlementSource::class);
        $this->app->forgetInstance(EntitlementService::class);
    }

    /**
     * @param array<int, CheckResult> $results
     */
    private function check(array $results): ?CheckResult
    {
        foreach ($results as $result) {
            if ($result->name === 'Licence') {
                return $result;
            }
        }

        return null;
    }

    private function learner(string $name): Learner
    {
        return Learner::factory()->create([
            'legal_name' => $name,
            'status_id' => LearnerStatus::query()->where('code', 'ACTIVE')->firstOrFail()->getKey(),
        ]);
    }

    /**
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    private function inTenant(Closure $callback): mixed
    {
        return app(TenantContext::class)->runAs($this->tenant, $callback);
    }
}
