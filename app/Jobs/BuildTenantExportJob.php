<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TenantExport;
use App\Services\TenantExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Builds an export in the background.
 *
 * Deliberately not tenant-aware in the usual way: the service binds the tenant itself from the
 * export row, because an export is sometimes produced for a cancelled account that nobody is
 * currently signed into.
 */
final class BuildTenantExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly int $exportId) {}

    public function handle(TenantExportService $exports): void
    {
        $export = TenantExport::withoutGlobalScopes()->find($this->exportId);

        if ($export === null) {
            return;
        }

        $exports->build($export);
    }
}
