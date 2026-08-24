<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\EnrollmentStatus;
use App\Models\GradingScheme;
use App\Models\IdSequence;
use App\Models\PresetApplication;
use App\Models\ReportTemplate;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\Presets\PresetApplier;
use App\Support\Presets\PresetRepository;
use App\Support\Tenancy\TenantContext;
use App\Support\Terminology\Terminology;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A preset is the answer to the objection that a fully configurable product is unusable on day
 * one. These tests hold it to that: applied, an account has working vocabularies; re-applied, it
 * keeps whatever the customer has since changed.
 */
final class PresetApplicationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = app(TenantContext::class)->withoutScoping(
            fn () => Tenant::factory()->create(['slug' => 'preset-test']),
        );
    }

    #[Test]
    public function applying_a_preset_makes_an_account_usable(): void
    {
        app(PresetApplier::class)->apply($this->tenant, 'kids-tutoring-south-asia');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            // Everything a teacher needs to take a register and grade work exists.
            $this->assertGreaterThan(0, AttendanceStatus::query()->count());
            $this->assertGreaterThan(0, EnrollmentStatus::query()->count());
            $this->assertGreaterThan(0, AssessmentType::query()->count());
            $this->assertGreaterThan(0, GradingScheme::query()->count());
            $this->assertNotNull(ReportTemplate::query()->where('is_default', true)->first());
            $this->assertNotNull(IdSequence::query()->where('entity', 'learner')->first());
        });
    }

    #[Test]
    public function a_south_asian_preset_starts_the_week_on_sunday(): void
    {
        app(PresetApplier::class)->apply($this->tenant, 'kids-tutoring-south-asia');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertSame('sunday', Setting::query()->where('key', 'locale.week_start')->value('value'));
            $this->assertSame(['saturday'], Setting::query()->where('key', 'locale.weekend_days')->value('value'));
        });
    }

    #[Test]
    public function a_gulf_preset_sets_a_friday_saturday_weekend(): void
    {
        app(PresetApplier::class)->apply($this->tenant, 'language-school-gulf');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            // The assumption that a weekend is Saturday and Sunday is the single most common way
            // school software fails outside its home country.
            $this->assertSame(
                ['friday', 'saturday'],
                Setting::query()->where('key', 'locale.weekend_days')->value('value'),
            );
        });
    }

    #[Test]
    public function an_adult_preset_switches_guardians_off(): void
    {
        app(PresetApplier::class)->apply($this->tenant, 'language-school-europe');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $this->assertFalse((bool) Setting::query()->where('key', 'people.guardians_enabled')->value('value'));
        });
    }

    #[Test]
    public function a_preset_that_lists_its_own_types_does_not_also_get_the_defaults(): void
    {
        app(PresetApplier::class)->apply($this->tenant, 'language-school-europe');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $codes = AssessmentType::query()->pluck('code')->all();

            $this->assertContains('SPEAKING', $codes);
            // A language school declaring its four skills should not silently also inherit
            // Homework and Quiz from the shared defaults.
            $this->assertNotContains('HOMEWORK', $codes);
        });
    }

    #[Test]
    public function terminology_arrives_with_the_preset(): void
    {
        app(PresetApplier::class)->apply($this->tenant, 'corporate-training');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            app(Terminology::class)->forget();

            $this->assertSame('Participant', app(Terminology::class)->singular('learner'));
            $this->assertSame('Cohorts', app(Terminology::class)->plural('batch'));
        });
    }

    #[Test]
    public function re_applying_keeps_whatever_the_customer_changed(): void
    {
        app(PresetApplier::class)->apply($this->tenant, 'kids-tutoring-south-asia');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            // The customer decides late arrivals get twenty minutes, not ten.
            Setting::query()->where('key', 'attendance.late_grace_minutes')->update(['value' => 20]);
            AttendanceStatus::query()->where('code', 'LATE')->update(['name' => 'Arrived late']);
        });

        app(PresetApplier::class)->apply($this->tenant, 'kids-tutoring-south-asia');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            // A starting point that overwrites your work is not a starting point.
            $this->assertSame(20, Setting::query()->where('key', 'attendance.late_grace_minutes')->value('value'));
            $this->assertSame('Arrived late', AttendanceStatus::query()->where('code', 'LATE')->value('name'));
            $this->assertSame(1, AttendanceStatus::query()->where('code', 'LATE')->count());
        });
    }

    #[Test]
    public function every_application_is_recorded_with_its_version(): void
    {
        app(PresetApplier::class)->apply($this->tenant, 'skills-institute');

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $application = PresetApplication::query()->latest('id')->first();

            $this->assertSame('skills-institute', $application->preset_code);
            $this->assertSame(1, $application->version);
            $this->assertArrayHasKey('assessment_types', $application->summary);
        });

        $this->assertSame('skills-institute', $this->tenant->refresh()->preset_code);
    }

    #[Test]
    public function every_preset_in_the_catalogue_applies_cleanly(): void
    {
        foreach (app(PresetRepository::class)->all() as $code => $preset) {
            $tenant = app(TenantContext::class)->withoutScoping(
                fn () => Tenant::factory()->create(['slug' => 'preset-'.$code]),
            );

            $summary = app(PresetApplier::class)->apply($tenant, $code);

            $this->assertGreaterThan(0, array_sum($summary), "Preset [{$code}] created nothing.");

            app(TenantContext::class)->runAs($tenant, function () use ($code): void {
                $this->assertGreaterThan(
                    0,
                    AttendanceStatus::query()->count(),
                    "Preset [{$code}] left an account unable to take a register.",
                );
            });
        }
    }
}
