<?php

namespace App\Filament\Resources\SiteVisits;

use App\Filament\Resources\SiteVisits\Pages\CreateSiteVisit;
use App\Filament\Resources\SiteVisits\Pages\ListSiteVisits;
use App\Filament\Resources\SiteVisits\Pages\ViewSiteVisit;
use App\Filament\Resources\Tickets\TicketForm;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Tickets\TicketViews;
use App\Models\Outage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Site visits (installation, Wi-Fi extender, support, shifting): booked, dispatched to a
 * technician, completed with evidence, verified by the dispatcher and confirmed with the customer.
 */
class SiteVisitResource extends Resource
{
    protected static ?string $model = Outage::class;

    protected static ?string $slug = 'site-visits';

    protected static ?string $modelLabel = 'site visit';

    protected static ?string $pluralModelLabel = 'site visits';

    protected static ?string $navigationLabel = 'Site Visits';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'ticket_number';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->siteVisits();
    }

    /** The search box at the top finds a ticket by its number, title, account number, customer name or phone. */
    public static function getGloballySearchableAttributes(): array
    {
        return TicketViews::GLOBAL_SEARCH;
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['ticket', 'status']);
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return TicketViews::globalSearchDetails($record);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Cache::remember('oms.nav.open-visits', 30, fn () => Outage::query()->siteVisits()->active()->count());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Anyone who may view tickets can open a site visit they are sent to (My Work, a notification,
     * the customer ticket it came from). The list, the menu entry and the search need "visit.view".
     */
    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('ticket.view');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canGloballySearch(): bool
    {
        return parent::canGloballySearch() && static::canViewAny();
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? ($action instanceof BackedEnum ? (string) $action->value : $action->name) : $action;
        $user = Auth::user();

        $allowed = $user !== null && match ($action) {
            // The list and the menu entry have their own switch; a visit someone is sent to still opens.
            'viewAny' => $user->can('ticket.view') && $user->can('visit.view'),
            'view' => $record ? $user->can('view', $record) : $user->can('ticket.view'),
            'create' => $user->can('createVisit', Outage::class),
            default => false,
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return TicketForm::visit($schema);
    }

    public static function table(Table $table): Table
    {
        return TicketViews::table($table, visits: true);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TicketViews::infolist($schema, visits: true);
    }

    public static function getRelations(): array
    {
        return TicketResource::relations();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSiteVisits::route('/'),
            'create' => CreateSiteVisit::route('/create'),
            'view' => ViewSiteVisit::route('/{record}'),
        ];
    }
}
