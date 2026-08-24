<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ReportDelivery;
use App\Services\ReportDeliveryService;
use App\Support\Tenancy\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one report to one recipient.
 *
 * Separate from generation so that a mail outage does not require regenerating PDFs, and so a
 * single bounced address can be fixed and retried on its own.
 */
final class SendReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public int $tries = 2;

    public function __construct(public readonly int $deliveryId)
    {
        $this->initializeTenantAware();
    }

    public function handle(ReportDeliveryService $deliveries): void
    {
        $delivery = ReportDelivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        $deliveries->send($delivery);
    }
}
