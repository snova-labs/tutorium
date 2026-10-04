<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Support\Tenancy\RequiresTenant;
use App\Support\Tenancy\ResolveTenant;
use App\Support\Tenancy\TenantContext;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The administrative panel.
 *
 * Deliberately mounted at /admin rather than at the root: the teacher-facing
 * screens are the product, and this is the surface an owner or a coordinator
 * visits occasionally to change how things are set up.
 *
 * Tenancy is **not** delegated to Filament's own multi-tenancy feature. That
 * feature scopes queries by adding constraints Filament controls; this
 * application scopes them with a global scope that every query passes through
 * whether Filament is involved or not. Two scoping mechanisms disagreeing about
 * which one is authoritative is exactly the class of bug the architecture
 * exists to prevent, so the panel simply runs inside the same tenant context
 * as everything else (SL-ARC-002 §3).
 */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                // The graphite and amber of the design system. Amber is reserved
                // almost entirely for marking an overridden value, so it is the
                // warning colour here rather than the primary one.
                'primary' => Color::Slate,
                'warning' => Color::Amber,
            ])
            ->font('Inter')
            ->brandName(fn () => app(TenantContext::class)->get()->name ?? config('platform.name'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // Order matters here exactly as it does on the API. The user must
                // exist before their tenant can be resolved from them, so these
                // run in the auth stack rather than the general one.
                ResolveTenant::class,
                RequiresTenant::class,
            ])
            ->databaseNotifications();
    }
}
