<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Widgets\CommandCentreOverview;
use App\Filament\Widgets\CoreOutagesTable;
use App\Filament\Widgets\ManagementInsights;
use App\Filament\Widgets\NetworkOutagesTable;
use App\Filament\Widgets\OutageKpiOverview;
use App\Filament\Widgets\OutagesByPriorityChart;
use App\Filament\Widgets\OutagesByStatusChart;
use App\Filament\Widgets\RecentOutagesTable;
use App\Filament\Widgets\SevenDayTrendChart;
use App\Filament\Widgets\SlaCustomerOverview;
use App\Filament\Widgets\SystemOutagesTable;
use App\Http\Middleware\EnsureUserIsActive;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(isSimple: false)
            ->passwordReset()
            // Authenticator-app MFA with recovery codes; set OMS_REQUIRE_MFA=true to enforce for everyone.
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()],
                isRequired: fn () => (bool) config('oms.security.require_mfa'),
            )
            ->brandName(fn () => config('app.name'))
            // App icon + name in the sidebar / top bar; larger stacked version on the sign-in pages.
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight(fn () => request()->routeIs('filament.*.auth.*') ? '9.5rem' : '2.25rem')
            ->favicon(asset('favicon.ico'))
            ->renderHook(PanelsRenderHook::HEAD_START, fn () => view('filament.head-icons'))
            // OMS styles, cache-busted by content hash so every deploy reaches browsers immediately.
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => new HtmlString(
                '<link rel="stylesheet" href="'.e(asset('css/oms.css')).'?v='.substr((string) @md5_file(public_path('css/oms.css')), 0, 12).'">'
            ))
            ->colors([
                'primary' => Color::Amber,
                'danger' => Color::Red,
                'warning' => Color::Orange,
                'success' => Color::Green,
                'info' => Color::Blue,
                'gray' => Color::Zinc,
            ])
            ->spa()
            ->unsavedChangesAlerts()
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->databaseNotifications()
            // Reverb pushes new notifications instantly (via the queue worker); polling keeps the
            // bell current when Reverb or the worker is not running.
            ->databaseNotificationsPolling('30s')
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->navigationGroups([
                NavigationGroup::make('Operations'),
                NavigationGroup::make('4G NOC'),
                NavigationGroup::make('Customer Care'),
                NavigationGroup::make('Reporting'),
                NavigationGroup::make('Topology')->collapsed(),
                NavigationGroup::make('Configuration')->collapsed(),
                NavigationGroup::make('People')->collapsed(),
                NavigationGroup::make('Monitoring')->collapsed(),
            ])
            ->navigationItems([
                // The audit trail filtered to the 4G NOC module: ticket actions, vendors, topology, rules, settings.
                NavigationItem::make('4G Audit Trail')
                    ->group('4G NOC')
                    ->icon(Heroicon::OutlinedFingerPrint)
                    ->sort(12)
                    ->visible(fn () => (bool) Auth::user()?->can('audit.view') && (bool) Auth::user()?->can('mobility.view'))
                    ->url(fn () => AuditLogResource::getUrl('index', ['filters' => ['scope' => ['value' => 'mobility']]])),
                // The same, for customer tickets, site visits, customers and the ticketing configuration.
                NavigationItem::make('Ticket Audit Trail')
                    ->group('Customer Care')
                    ->icon(Heroicon::OutlinedFingerPrint)
                    ->sort(31)
                    ->visible(fn () => (bool) Auth::user()?->can('audit.view') && (bool) Auth::user()?->can('ticket.view'))
                    ->url(fn () => AuditLogResource::getUrl('index', ['filters' => ['scope' => ['value' => 'ticketing']]])),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                CommandCentreOverview::class,
                OutageKpiOverview::class,
                SlaCustomerOverview::class,
                SevenDayTrendChart::class,
                OutagesByStatusChart::class,
                OutagesByPriorityChart::class,
                NetworkOutagesTable::class,
                CoreOutagesTable::class,
                SystemOutagesTable::class,
                RecentOutagesTable::class,
                ManagementInsights::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureUserIsActive::class,
            ]);
    }
}
