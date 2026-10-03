<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Services\WebhookProcessor;
use App\Support\Payments\PaymentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Events from the payment provider.
 *
 * Two rules. Verify the signature before reading anything, because this endpoint is public by
 * necessity. Then record the event and key on the provider's own id, because providers retry and
 * applying a payment twice is a worse failure than applying it late. What each event means lives
 * in WebhookProcessor.
 */
final class PaymentWebhookController
{
    public function __construct(
        private readonly PaymentProvider $payments,
        private readonly WebhookProcessor $processor,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        if (! $this->payments->verifyWebhook($payload, $signature)) {
            // No detail: an unverified caller learns nothing about why.
            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        $data = json_decode($payload, true) ?: [];

        if (! isset($data['id'])) {
            return response()->json(['message' => 'Malformed event.'], 422);
        }

        $event = $this->processor->record($this->payments->name(), $data);

        if ($event->isProcessed()) {
            // Already handled. Acknowledged so the provider stops retrying.
            return response()->json(['message' => 'Already processed.']);
        }

        if (! $this->processor->process($event)) {
            // A 500 asks the provider to retry, which is what we want for a transient failure.
            return response()->json(['message' => 'Could not process.'], 500);
        }

        return response()->json(['message' => 'Processed.']);
    }
}
