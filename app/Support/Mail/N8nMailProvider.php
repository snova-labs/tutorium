<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Hands the message to an n8n workflow, which owns retries and provider choice.
 *
 * Useful while experimenting with sending providers, since switching one is a change to the flow
 * rather than a deploy. Not the default, because an extra moving part on the critical path of
 * "did the parent get the report" needs to earn its place.
 */
final class N8nMailProvider implements MailProvider
{
    public function __construct(
        private readonly string $webhookUrl,
        private readonly ?string $signingSecret = null,
    ) {}

    public function send(MailMessage $message): MailResult
    {
        $payload = [
            'to' => $message->to,
            'to_name' => $message->toName,
            'subject' => $message->subject,
            'html' => $message->html,
            'from' => ['address' => $message->fromAddress, 'name' => $message->fromName],
            'reply_to' => $message->replyTo,
            'attachments' => array_map(static fn (array $a): array => [
                'name' => $a['name'],
                'mime' => $a['mime'],
                'content_base64' => base64_encode($a['content']),
            ], $message->attachments),
        ];

        try {
            $request = Http::timeout(30)->asJson();

            // Signed so the workflow can reject anything that did not come from us — the endpoint
            // is public by necessity.
            if ($this->signingSecret !== null) {
                $request = $request->withHeader(
                    'X-Signature',
                    hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $this->signingSecret),
                );
            }

            $response = $request->post($this->webhookUrl, $payload);

            if ($response->failed()) {
                return MailResult::failed($this->name(), 'Workflow responded '.$response->status());
            }

            return MailResult::sent($this->name(), $response->json('id'));
        } catch (Throwable $e) {
            return MailResult::failed($this->name(), $e->getMessage());
        }
    }

    public function name(): string
    {
        return 'n8n';
    }
}
