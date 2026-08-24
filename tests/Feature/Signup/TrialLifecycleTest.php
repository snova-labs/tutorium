<?php

declare(strict_types=1);

namespace Tests\Feature\Signup;

use App\Models\AuditLog;
use App\Models\BillingProfile;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantExport;
use App\Notifications\TrialEndingNotification;
use App\Services\SubscriptionService;
use App\Services\TenantExportService;
use App\Services\TenantProvisioner;
use App\Services\TrialService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What happens at the end of a trial, and why nobody has to re-type anything.
 */
final class TrialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->tenant = app(TenantProvisioner::class)->provision([
            'name' => 'Sample Academy',
            'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@sample.test',
            'password' => 'a-long-enough-password',
            'timezone' => 'Asia/Kathmandu',
            'preset_code' => 'kids-tutoring-south-asia',
            'trial_days' => 14,
        ])['tenant'];
    }

    #[Test]
    public function the_status_says_what_will_happen_before_it_happens(): void
    {
        $status = app(TrialService::class)->status($this->tenant);

        $this->assertTrue($status['in_trial']);
        $this->assertSame(14, $status['days_left']);

        $explanation = implode(' ', $status['what_happens_at_the_end']);
        $this->assertStringContainsString('read, download and export', $explanation);
        $this->assertStringContainsString('nothing is rebuilt', $explanation);
    }

    #[Test]
    public function reminders_go_out_once_each_at_seven_three_and_one_days(): void
    {
        $service = app(TrialService::class);

        $this->travel(7)->days();
        $this->assertSame(1, $service->sendDueReminders()['sent']);
        // Running twice on the same day must not send twice.
        $this->assertSame(0, $service->sendDueReminders()['sent']);

        $this->travel(4)->days();
        $this->assertSame(1, $service->sendDueReminders()['sent']);

        $this->travel(2)->days();
        $this->assertSame(1, $service->sendDueReminders()['sent']);

        Notification::assertCount(3);
    }

    #[Test]
    public function an_expired_trial_becomes_read_only_and_keeps_everything(): void
    {
        $this->travel(15)->days();

        $this->assertSame(1, app(TrialService::class)->expireDue()['expired']);

        $tenant = $this->tenant->refresh();
        $this->assertTrue($tenant->isSuspended());
        $this->assertNotNull($tenant->trial_expired_at);

        app(TenantContext::class)->runAs($tenant, function (): void {
            // Everything set up during the trial is still there.
            $this->assertTrue(Branch::query()->exists());
        });

        // And still exportable, exactly as with a suspended paying account.
        $export = app(TenantExportService::class)->request($tenant);
        $this->assertSame(TenantExport::STATUS_QUEUED, $export->status);
    }

    #[Test]
    public function the_customer_can_read_why_in_their_own_activity_log(): void
    {
        $this->travel(15)->days();
        app(TrialService::class)->expireDue();

        app(TenantContext::class)->runAs($this->tenant, function (): void {
            $entry = AuditLog::query()->where('action', 'status_changed')->latest('id')->first();

            $this->assertStringContainsString('Trial ended', $entry->after['reason']);
            $this->assertStringContainsString('remains exportable', $entry->after['reason']);
        });
    }

    #[Test]
    public function converting_changes_nothing_about_the_data(): void
    {
        app(TenantContext::class)->runAs($this->tenant, fn () => Branch::query()->create([
            'brand_id' => \App\Models\Brand::query()->first()->getKey(),
            'name' => 'Second location',
            'code' => 'TWO',
            'timezone' => 'Asia/Kathmandu',
        ]));

        $converted = app(TrialService::class)->convert($this->tenant);

        $this->assertSame(Tenant::STATUS_ACTIVE, $converted->status);
        $this->assertNull($converted->trial_ends_at);

        app(TenantContext::class)->runAs($converted, function (): void {
            $this->assertSame(2, Branch::query()->count(), 'Everything carries over untouched.');
        });
    }

    #[Test]
    public function someone_who_has_added_a_card_converts_rather_than_expires(): void
    {
        $plan = Plan::query()->create([
            'code' => 'growth', 'name' => 'Growth', 'currency' => 'EUR',
            'unit_price_minor' => 200, 'minimum_charge_minor' => 0,
        ]);

        app(SubscriptionService::class)->subscribe($this->tenant, $plan);

        app(TenantContext::class)->runAs($this->tenant, fn () => BillingProfile::query()->create([
            'provider' => 'fake', 'customer_ref' => 'cus_1',
            'method_brand' => 'Visa', 'method_last_four' => '4242',
            'method_exp_month' => 9, 'method_exp_year' => 2029,
        ]));

        $this->travel(15)->days();
        app(TrialService::class)->expireDue();

        // The intent is unambiguous, so a date passing does not lock them out.
        $tenant = $this->tenant->refresh();
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);
        $this->assertNull($tenant->trial_expired_at);
    }

    #[Test]
    public function a_reminder_to_someone_who_has_not_started_offers_help_not_a_countdown(): void
    {
        $this->travel(7)->days();
        app(TrialService::class)->sendDueReminders();

        Notification::assertSentTo(
            app(TenantContext::class)->runAs($this->tenant, fn () => \App\Models\User::query()->first()),
            TrialEndingNotification::class,
            function (TrialEndingNotification $notification) {
                $mail = $notification->toMail(new \stdClass);
                $body = implode(' ', array_map('strval', $mail->introLines));

                // No batch exists yet, so the useful message is about finishing setup.
                return str_contains($body, 'does anything useful for you yet');
            },
        );
    }
}
