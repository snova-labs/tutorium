<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Enums\DeliveryStatus;
use App\Models\Guardian;
use App\Models\Report;
use App\Models\ReportDelivery;
use App\Models\User;
use App\Services\ReportDeliveryService;
use App\Services\ReportService;
use App\Support\Mail\MailMessage;
use App\Support\Mail\MailProvider;
use App\Support\Mail\MailResult;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\PeriodService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Reporting\Concerns\BuildsReportingScenario;
use Tests\TestCase;

final class ReportDeliveryTest extends TestCase
{
    use BuildsReportingScenario, RefreshDatabase;

    #[Test]
    public function every_flagged_guardian_gets_their_own_delivery_row(): void
    {
        Storage::fake('local');

        $this->inTenant(function (): void {
            $second = Guardian::factory()->create(['name' => 'Sample Guardian B', 'email' => 'b@example.test']);
            $this->enrollment->learner->guardians()->attach($second->getKey(), [
                'tenant_id' => $this->enrollment->tenant_id, 'receives_reports' => true,
            ]);

            $third = Guardian::factory()->create(['name' => 'Sample Guardian C', 'email' => 'c@example.test']);
            $this->enrollment->learner->guardians()->attach($third->getKey(), [
                'tenant_id' => $this->enrollment->tenant_id, 'receives_reports' => false,
            ]);

            $report = $this->readyReport();
            $deliveries = app(ReportDeliveryService::class)->queue($report);

            // Two rows, not three: a household where one parent opted out is a flag, not a
            // duplicated learner.
            $this->assertCount(2, $deliveries);
            $this->assertNotContains('c@example.test', $deliveries->pluck('to_address')->all());
        });
    }

    #[Test]
    public function a_bounced_address_is_recorded_rather_than_thrown(): void
    {
        Storage::fake('local');
        $this->useMailer(fn () => MailResult::failed('smtp', 'Domain not found'));

        $this->inTenant(function (): void {
            $report = $this->readyReport();
            $delivery = app(ReportDeliveryService::class)->queue($report)->first();

            app(ReportDeliveryService::class)->send($delivery);

            $delivery->refresh();

            // An operational fact for the record, not an exception that aborts a run of forty.
            $this->assertSame(DeliveryStatus::Failed, $delivery->status);
            $this->assertSame('Domain not found', $delivery->error);
            $this->assertSame(1, $delivery->retry_count);
            $this->assertTrue($delivery->status->isRetryable());
        });
    }

    #[Test]
    public function a_failed_delivery_can_be_retried_after_the_address_is_fixed(): void
    {
        Storage::fake('local');
        $mailbox = new class
        {
            public bool $failing = true;
        };
        $this->useMailer(fn () => $mailbox->failing
            ? MailResult::failed('smtp', 'Domain not found')
            : MailResult::sent('smtp', 'msg-1'));

        $this->inTenant(function () use ($mailbox): void {
            $report = $this->readyReport();
            $delivery = app(ReportDeliveryService::class)->queue($report)->first();

            app(ReportDeliveryService::class)->send($delivery);
            $this->assertSame(DeliveryStatus::Failed, $delivery->refresh()->status);

            $mailbox->failing = false;
            app(ReportDeliveryService::class)->retry($delivery);
            app(ReportDeliveryService::class)->send($delivery->refresh());

            $delivery->refresh();
            $this->assertSame(DeliveryStatus::Sent, $delivery->status);
            $this->assertSame('msg-1', $delivery->provider_ref);
            $this->assertNull($delivery->error);
        });
    }

    #[Test]
    public function the_report_is_attached_and_the_reply_goes_to_the_academy(): void
    {
        Storage::fake('local');
        $captured = null;
        $this->useMailer(function (MailMessage $message) use (&$captured) {
            $captured = $message;

            return MailResult::sent('smtp');
        });

        $this->inTenant(function () use (&$captured): void {
            $delivery = app(ReportDeliveryService::class)->queue($this->readyReport())->first();
            app(ReportDeliveryService::class)->send($delivery);

            $this->assertCount(1, $captured->attachments);
            $this->assertSame('application/pdf', $captured->attachments[0]['mime']);
            $this->assertStringStartsWith('%PDF', $captured->attachments[0]['content']);

            // A parent who replies should reach their academy, never us.
            $this->assertSame('reports@sample.test', $captured->replyTo);
            $this->assertSame('Sample Brand', $captured->fromName);
        });
    }

    #[Test]
    public function a_learner_with_nobody_to_send_to_is_refused_clearly(): void
    {
        Storage::fake('local');

        $this->inTenant(function (): void {
            $this->enrollment->learner->guardians()->detach();
            $report = $this->readyReport();

            try {
                app(ReportDeliveryService::class)->queue($report);
                $this->fail('A report with no recipients should be refused.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Nobody is set to receive reports', $e->getMessage());
            }
        });
    }

    #[Test]
    public function an_adult_learner_receives_their_own_report(): void
    {
        Storage::fake('local');

        $this->inTenant(function (): void {
            // Language schools and skills institutes switch guardians off entirely.
            $this->enrollment->learner->guardians()->detach();
            $this->enrollment->learner->update(['email' => 'adult.learner@example.test']);

            $deliveries = app(ReportDeliveryService::class)->queue($this->readyReport());

            $this->assertCount(1, $deliveries);
            $this->assertSame('adult.learner@example.test', $deliveries->first()->to_address);
            $this->assertSame('learner', $deliveries->first()->recipient_type->value);
        });
    }

    #[Test]
    public function a_report_that_may_not_be_sent_yet_is_still_generated(): void
    {
        Storage::fake('local');

        $this->inTenant(function (): void {
            // Nobody on the account has confirmed an address, so sending to families is held.
            User::query()->update(['email_verified_at' => null]);
            $this->markAllPresent();

            $run = app(ReportService::class)->queueBatchRun($this->batch, '2026-08', ['send' => true, 'require_complete' => false]);
            $run->refresh();

            $this->assertSame(1, $run->succeeded, 'Generating is not blocked by sending.');
            $this->assertSame(0, $run->failed);
            $this->assertStringStartsWith('Generated but not sent', $run->failures[0]['reason']);
            $this->assertSame(1, Report::query()->count());
            $this->assertSame(0, ReportDelivery::query()->count());
        });
    }

    #[Test]
    public function queueing_twice_does_not_duplicate_a_recipients_delivery(): void
    {
        Storage::fake('local');

        $this->inTenant(function (): void {
            $report = $this->readyReport();

            app(ReportDeliveryService::class)->queue($report);
            app(ReportDeliveryService::class)->queue($report);

            $this->assertSame(1, ReportDelivery::query()->where('report_id', $report->getKey())->count());
        });
    }

    private function readyReport(): Report
    {
        $this->markAllPresent();
        $this->gradeHomework(16);

        $period = app(PeriodService::class)->forLabel($this->batch->load('course'), '2026-08');

        return app(ReportService::class)->generate(
            $this->enrollment->setRelation('batch', $this->batch),
            $period,
        );
    }

    private function useMailer(Closure $handler): void
    {
        $this->app->instance(MailProvider::class, new class($handler) implements MailProvider
        {
            public function __construct(private Closure $handler) {}

            public function send(MailMessage $message): MailResult
            {
                return ($this->handler)($message);
            }

            public function name(): string
            {
                return 'smtp';
            }
        });
    }

    /** @param Closure(): void $callback */
    private function inTenant(Closure $callback): void
    {
        app(TenantContext::class)->runAs($this->tenant, $callback);
    }
}
