<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\TrialService;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\Widget;

/**
 * The trial notice.
 *
 * Says what will happen and what will not, rather than counting down at
 * somebody. An academy mid-trial has enough to think about.
 */
final class TrialBanner extends Widget
{
    protected string $view = 'filament.widgets.trial-banner';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -10;

    public static function canView(): bool
    {
        $tenant = app(TenantContext::class)->get();

        return $tenant !== null && $tenant->status === \App\Models\Tenant::STATUS_TRIAL;
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        return ['trial' => app(TrialService::class)->status(app(TenantContext::class)->require())];
    }
}
