<?php

namespace App\Filament\Resources\Tickets;

use App\Filament\Resources\MobilityOutages\RelationManagers\ReopeningsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\AssignmentHistoriesRelationManager;
use App\Filament\Resources\Outages\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\AuditLogsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\NotificationLogsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\SlaEventsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\DeskMovesRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\FollowUpsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\TicketAttachmentsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\TicketEventsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\TicketTimelineRelationManager;
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
 * Customer tickets: raised by CX, worked desk by desk (CX Tier II, Billing, Commercial, NOC…).
 * Same Outage model as the network outages (so FIFO, SLA, notifications and audit are shared)
 * with its own screens, statuses and actions.
 */
class TicketResource extends Resource
{
    protected static ?string $model = Outage::class;

    protected static ?string $slug = 'tickets';

    protected static ?string $modelLabel = 'customer ticket';

    protected static ?string $pluralModelLabel = 'customer tickets';

    protected static ?string $navigationLabel = 'Customer Tickets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'ticket_number';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->cxTickets();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['ticket_number', 'title'];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Cache::remember('oms.nav.open-tickets', 30, fn () => Outage::query()->cxTickets()->active()->count());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Filament asks the model policy by default (the outage rules); tickets have their own, mapped here. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? ($action instanceof BackedEnum ? (string) $action->value : $action->name) : $action;
        $user = Auth::user();

        $allowed = $user !== null && match ($action) {
            'viewAny' => $user->can('ticket.view'),
            'view' => $record ? $user->can('view', $record) : $user->can('ticket.view'),
            'create' => $user->can('createTicket', Outage::class),
            default => false, // tickets are changed through their actions and never deleted from the UI
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return TicketForm::ticket($schema);
    }

    public static function table(Table $table): Table
    {
        return TicketViews::table($table, visits: false);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TicketViews::infolist($schema, visits: false);
    }

    public static function getRelations(): array
    {
        return self::relations();
    }

    /** Tabs under a ticket / site visit page. */
    public static function relations(): array
    {
        return [
            TicketTimelineRelationManager::class,
            TicketEventsRelationManager::class,
            DeskMovesRelationManager::class,
            FollowUpsRelationManager::class,
            TicketAttachmentsRelationManager::class,
            ReopeningsRelationManager::class,
            StatusHistoriesRelationManager::class,
            AssignmentsRelationManager::class,
            AssignmentHistoriesRelationManager::class,
            SlaEventsRelationManager::class,
            NotificationLogsRelationManager::class,
            AuditLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'create' => CreateTicket::route('/create'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
