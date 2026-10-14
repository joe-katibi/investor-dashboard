<?php

namespace App\Filament\Resources\FourGTickets;

use App\Enums\QueueType;
use App\Filament\Resources\FourGTickets\Pages\ListFourGTickets;
use App\Filament\Resources\FourGTickets\Pages\ViewFourGTicket;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Tickets\TicketViews;
use App\Models\Outage;
use App\Models\User;
use App\Models\Vertical;
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
 * 4G customer tickets, for the 4G NOC and its IP, RF and Core teams: raised by CX like any customer
 * ticket (Vertical: 4G), escalated by CX Tier II to the 4G NOC, from there to a team and its vendor,
 * validated by the NOC and confirmed with the customer by CX Tier II. Raised on Customer Tickets.
 */
class FourGTicketResource extends Resource
{
    protected static ?string $model = Outage::class;

    protected static ?string $slug = '4g-tickets';

    protected static ?string $modelLabel = '4G ticket';

    protected static ?string $pluralModelLabel = '4G tickets';

    protected static ?string $navigationLabel = '4G Tickets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = '4G NOC';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'ticket_number';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->cxTickets()->where('vertical_id', Vertical::idFor(Vertical::MOBILITY_4G) ?? 0);
    }

    /** The top search finds 4G tickets through Customer Tickets already. */
    public static function canGloballySearch(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Cache::remember('oms.nav.open-4g-tickets', 30, fn () => static::getEloquentQuery()->active()->count());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** The list is for the 4G NOC, its IP / RF / Core teams, CX Tier II and whoever sees the 4G NOC module. */
    public static function canSeeList(?User $user): bool
    {
        if (! $user?->can('ticket.view')) {
            return false;
        }

        $permissions = ['mobility.view', QueueType::CxTier2->defaultPermission(), QueueType::Ticket4gNoc->defaultPermission(),
            QueueType::TicketIp->defaultPermission(), QueueType::TicketRf->defaultPermission(), QueueType::TicketCore->defaultPermission()];

        return collect($permissions)->contains(fn (string $permission) => $user->can($permission));
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? ($action instanceof BackedEnum ? (string) $action->value : $action->name) : $action;
        $user = Auth::user();

        $allowed = $user !== null && match ($action) {
            'viewAny' => static::canSeeList($user),
            'view' => $record ? $user->can('view', $record) : $user->can('ticket.view'),
            default => false, // raised on Customer Tickets; changed through the ticket's actions
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function table(Table $table): Table
    {
        return TicketViews::table($table, visits: false, resource: static::class);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TicketViews::infolist($schema, visits: false);
    }

    public static function getRelations(): array
    {
        return TicketResource::relations();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFourGTickets::route('/'),
            'view' => ViewFourGTicket::route('/{record}'),
        ];
    }
}
