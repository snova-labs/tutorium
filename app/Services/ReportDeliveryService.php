<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\RecipientType;
use App\Jobs\SendReportJob;
use App\Models\Brand;
use App\Models\EmailTemplate;
use App\Models\Guardian;
use App\Models\Report;
use App\Models\ReportDelivery;
use App\Support\Mail\MailMessage;
use App\Support\Mail\MailProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Getting a report to the people who should read it.
 */
final class ReportDeliveryService
{
    public function __construct(
        private readonly MailProvider $mailer,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Create a delivery row per intended recipient and queue each one.
     *
     * @return Collection<int, ReportDelivery>
     */
    public function queue(Report $report): Collection
    {
        $report->loadMissing(['enrollment.learner.guardians']);

        $recipients = $this->recipientsFor($report);

        if ($recipients === []) {
            throw ValidationException::withMessages([
                'recipients' => sprintf(
                    'Nobody is set to receive reports for %s. Add a guardian, or mark an existing one as a recipient.',
                    $report->enrollment->learner->displayName(),
                ),
            ]);
        }

        if (config('signup.require_verification_to_send')
            && ! app(SignupService::class)->maySendOutbound($this->tenancy->require())) {
            throw ValidationException::withMessages([
                'verification' => 'Nobody on this account has confirmed an email address yet, so reports '
                    .'cannot be emailed to families. Signing in with an emailed code confirms yours.',
            ]);
        }

        return collect($recipients)->map(function (array $recipient) use ($report): ReportDelivery {
            $delivery = ReportDelivery::query()->updateOrCreate(
                [
                    'report_id' => $report->getKey(),
                    'recipient_type' => $recipient['type'],
                    'recipient_id' => $recipient['id'],
                ],
                [
                    'recipient_name' => $recipient['name'],
                    'to_address' => $recipient['address'],
                    'status' => DeliveryStatus::Queued,
                    'error' => null,
                ],
            );

            SendReportJob::dispatch($delivery->getKey());

            return $delivery;
        });
    }

    /**
     * Send one delivery.
     *
     * Returns rather than throws on a rejected address: a bounced parent email is an operational
     * fact for the delivery record, not an exception that should abort a run.
     */
    public function send(ReportDelivery $delivery): ReportDelivery
    {
        $delivery->loadMissing(['report.enrollment.learner', 'report.enrollment.batch.course.brand']);
        $report = $delivery->report;
        $brand = $report->enrollment->batch->course->brand;

        $rendered = $this->email($report, $delivery, $brand);

        $result = $this->mailer->send(new MailMessage(
            to: $delivery->to_address,
            subject: $rendered['subject'],
            html: $rendered['body'],
            toName: $delivery->recipient_name,
            fromAddress: $brand->sender_email,
            fromName: $brand->sender_name ?? $brand->name,
            // Replies belong to the academy, never to us.
            replyTo: $brand->sender_email,
            attachments: $this->attachment($report),
        ));

        $result->sent
            ? $delivery->markSent($result->reference, $result->provider)
            : $delivery->markFailed((string) $result->error, $result->provider);

        return $delivery->refresh();
    }

    public function retry(ReportDelivery $delivery): ReportDelivery
    {
        if (! $delivery->status->isRetryable()) {
            throw ValidationException::withMessages([
                'delivery' => 'This delivery is not in a state that can be retried.',
            ]);
        }

        $delivery->update(['status' => DeliveryStatus::Queued]);
        SendReportJob::dispatch($delivery->getKey());

        return $delivery->refresh();
    }

    /** @return array<int, array{type: RecipientType, id: ?int, name: string, address: string}> */
    private function recipientsFor(Report $report): array
    {
        $learner = $report->enrollment->learner;

        $guardians = $learner->guardians()
            ->wherePivot('receives_reports', true)
            ->get()
            ->filter(fn (Guardian $g) => $g->deliveryAddress() !== null)
            ->map(fn (Guardian $g) => $this->recipient(
                RecipientType::Guardian, $g->id, $g->name, (string) $g->deliveryAddress(),
            ));

        // Adult learners in language schools and skills institutes receive their own reports, so
        // an academy with guardians switched off is not left with nobody to send to.
        if ($guardians->isEmpty() && $learner->email !== null) {
            return [
                $this->recipient(RecipientType::Learner, $learner->id, $learner->displayName(), $learner->email),
            ];
        }

        return $guardians->values()->all();
    }

    /** @return array<int, array{name: string, content: string, mime: string}> */
    private function attachment(Report $report): array
    {
        if ($report->file_path === null) {
            return [];
        }

        $disk = Storage::disk($report->file_disk ?? config('reporting.storage.disk'));

        if (! $disk->exists($report->file_path)) {
            return [];
        }

        return [[
            'name' => sprintf(
                '%s-%s.pdf',
                str_replace(' ', '-', $report->stats_snapshot['learner_name'] ?? 'report'),
                $report->stats_snapshot['period']['label'] ?? '',
            ),
            'content' => (string) $disk->get($report->file_path),
            'mime' => 'application/pdf',
        ]];
    }

    /** @return array{type: RecipientType, id: ?int, name: string, address: string} */
    private function recipient(RecipientType $type, ?int $id, string $name, string $address): array
    {
        return ['type' => $type, 'id' => $id, 'name' => $name, 'address' => $address];
    }

    /** @return array{subject: string, body: string} */
    private function email(Report $report, ReportDelivery $delivery, Brand $brand): array
    {
        $values = [
            'recipient_name' => (string) $delivery->recipient_name,
            'learner_name' => $report->stats_snapshot['learner_name'] ?? '',
            'period' => $report->stats_snapshot['period']['label'] ?? '',
            'brand_name' => $brand->name,
            'batch_name' => $report->stats_snapshot['batch_name'] ?? '',
        ];

        $template = EmailTemplate::query()
            ->where('code', 'report_delivery')
            ->where(fn ($q) => $q->where('brand_id', $brand->getKey())->orWhereNull('brand_id'))
            ->orderByRaw('brand_id IS NULL')
            ->first();

        if ($template !== null) {
            return $template->render($values);
        }

        return [
            'subject' => sprintf('%s — progress report for %s', $values['brand_name'], $values['period']),
            'body' => sprintf(
                '<p>Dear %s,</p><p>The progress report for <strong>%s</strong> covering %s is attached.</p>'
                .'<p>If anything in it raises a question, reply to this message and it will reach us directly.</p>'
                .'<p>%s</p>',
                e($values['recipient_name']),
                e($values['learner_name']),
                e($values['period']),
                e($values['brand_name']),
            ),
        ];
    }
}
