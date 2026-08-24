<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Tenant;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;

/**
 * Stripe.
 *
 * Invoices are created and paid rather than subscriptions being managed by Stripe, because the
 * quantity is our measurement and the reasoning behind it is ours to defend. Handing the metric to
 * the payment provider would mean a customer disputing a figure gets pointed at a third party.
 *
 * The HTTP calls here cannot be exercised without keys. Everything the rest of the system does with
 * a payment result is covered by FakePaymentProvider, so the untested surface is the adapter alone.
 */
final class StripePaymentProvider implements PaymentProvider
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $webhookSecret,
    ) {}

    public function syncCustomer(Tenant $tenant, BillingProfile $profile): string
    {
        $payload = [
            'name' => $profile->legal_name ?? $tenant->name,
            'email' => $profile->billing_email ?? $tenant->contact_email,
            'metadata' => ['tenant_id' => (string) $tenant->getKey(), 'tenant_slug' => $tenant->slug],
        ];

        if ($profile->tax_id !== null && $profile->country !== null) {
            $payload['tax_id_data'] = [[
                'type' => $this->taxIdType($profile->country),
                'value' => $profile->tax_id,
            ]];
        }

        if ($profile->customer_ref !== null) {
            unset($payload['tax_id_data']);
            $this->stripe->customers->update($profile->customer_ref, $payload);

            return $profile->customer_ref;
        }

        return $this->stripe->customers->create($payload)->id;
    }

    public function checkoutUrl(Tenant $tenant, BillingProfile $profile, string $returnUrl): ?string
    {
        // Setup mode, not payment mode: the customer is authorising a method for later metered
        // invoices, not buying anything today.
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'setup',
            'customer' => $profile->customer_ref,
            'success_url' => $returnUrl.'?status=ok',
            'cancel_url' => $returnUrl.'?status=cancelled',
        ]);

        return $session->url;
    }

    public function portalUrl(BillingProfile $profile, string $returnUrl): ?string
    {
        if ($profile->customer_ref === null) {
            return null;
        }

        return $this->stripe->billingPortal->sessions->create([
            'customer' => $profile->customer_ref,
            'return_url' => $returnUrl,
        ])->url;
    }

    public function charge(Invoice $invoice, BillingProfile $profile): PaymentResult
    {
        if ($profile->customer_ref === null) {
            return PaymentResult::failed($this->name(), 'no_customer', 'No payment method on file.');
        }

        try {
            $stripeInvoice = $this->stripe->invoices->create([
                'customer' => $profile->customer_ref,
                'collection_method' => 'charge_automatically',
                'currency' => strtolower($invoice->currency),
                'auto_advance' => false,
                'metadata' => [
                    'invoice_number' => $invoice->number,
                    'tenant_id' => (string) $invoice->tenant_id,
                    // Carried across so a Stripe record can be traced back to the day it was
                    // measured, without leaving our own system.
                    'quantity_basis_date' => (string) $invoice->quantity_basis_date?->toDateString(),
                ],
            ]);

            $this->stripe->invoiceItems->create([
                'customer' => $profile->customer_ref,
                'invoice' => $stripeInvoice->id,
                'currency' => strtolower($invoice->currency),
                'unit_amount' => $invoice->unit_price_minor,
                'quantity' => max(1, $invoice->quantity),
                'description' => sprintf(
                    'Active learners, %s to %s (peak %d on %s)',
                    $invoice->period_start->toDateString(),
                    $invoice->period_end->toDateString(),
                    $invoice->quantity,
                    $invoice->quantity_basis_date?->toDateString() ?? '—',
                ),
            ]);

            if ($invoice->minimum_adjustment_minor > 0) {
                $this->stripe->invoiceItems->create([
                    'customer' => $profile->customer_ref,
                    'invoice' => $stripeInvoice->id,
                    'currency' => strtolower($invoice->currency),
                    'amount' => $invoice->minimum_adjustment_minor,
                    'description' => 'Minimum monthly charge adjustment',
                ]);
            }

            $paid = $this->stripe->invoices->pay($stripeInvoice->id);

            return $paid->status === 'paid'
                ? PaymentResult::succeeded($this->name(), $paid->id)
                : PaymentResult::failed($this->name(), 'not_paid', 'Stripe returned status '.$paid->status);
        } catch (ApiErrorException $e) {
            $error = $e->getError();

            return PaymentResult::failed(
                $this->name(),
                $error?->code ?? 'stripe_error',
                $error?->message ?? $e->getMessage(),
            );
        } catch (Throwable $e) {
            return PaymentResult::failed($this->name(), 'stripe_error', $e->getMessage());
        }
    }

    public function verifyWebhook(string $payload, string $signature): bool
    {
        try {
            Webhook::constructEvent($payload, $signature, $this->webhookSecret);

            return true;
        } catch (Throwable) {
            // A failed signature is an unauthenticated request, not an error worth detail.
            return false;
        }
    }

    public function name(): string
    {
        return 'stripe';
    }

    private function taxIdType(string $country): string
    {
        return match (strtoupper($country)) {
            'GB' => 'gb_vat',
            'CH' => 'ch_vat',
            'AU' => 'au_abn',
            'CA' => 'ca_bn',
            'IN' => 'in_gst',
            'AE' => 'ae_trn',
            default => 'eu_vat',
        };
    }
}
