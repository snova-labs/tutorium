<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Services\WebhookProcessor;
use App\Support\Payments\PaymentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The payment provider's callback.
 *
 * Public by necessity, so nothing is trusted: the signature is verified before the body is read as
 * anything but a string, and an unverifiable request is discarded with a 400 rather than logged as
 * an error — anyone on the internet can post here.
 */
final class PaymentWebhookController
{
    public function __construct(
        private readonly PaymentProvider $payments,
        private readonly WebhookProcessor $processor,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $verified = $this->payments->verifyWebhook(
            $request->getContent(),
            $request->header('Stripe-Signature', ''),
        );

        if ($verified === null) {
            return response()->json(['message' => 'Signature could not be verified.'], 400);
        }

        $event = $this->processor->record($this->payments->name(), $verified);

        // Recorded before it is acted on, so a failure while processing does not lose the event.
        $this->processor->process($event);

        // Acknowledged either way. A provider that does not get a 200 will retry, and a retry of
        // something already handled is wasted work on both sides.
        return response()->json(['received' => true]);
    }
}
