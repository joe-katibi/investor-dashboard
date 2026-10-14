<?php

namespace App\Filament\Resources\FourGTickets\Pages;

use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\FourGTickets\FourGTicketResource;
use App\Filament\Resources\Tickets\TicketResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListFourGTickets extends ListRecords
{
    use RefreshesOnOmsEvents;

    protected static string $resource = FourGTicketResource::class;

    protected static ?string $title = '4G tickets';

    protected function getHeaderActions(): array
    {
        // Raised on Customer Tickets with Vertical: 4G, like every customer ticket.
        return [
            Action::make('raise')->label('Raise ticket')->icon(Heroicon::OutlinedPlus)
                ->url(fn () => TicketResource::getUrl('create'))
                ->visible(fn () => TicketResource::canCreate()),
        ];
    }

    public function getTabs(): array
    {
        $open = fn (Builder $query) => $query->where('is_closed', false);
        $desk = fn (QueueType ...$desks) => fn (Builder $query) => $open($query)->whereIn('current_queue', array_map(fn (QueueType $d) => $d->value, $desks));

        return [
            'open' => Tab::make('Open')->modifyQueryUsing($open),
            'mine' => Tab::make('Assigned to me')->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->where('current_assignee_id', Auth::id())),
            'raised' => Tab::make('Raised by me')->modifyQueryUsing(fn (Builder $query) => $open($query)->where('created_by', Auth::id())),
            'tier2' => Tab::make('CX Tier II')->modifyQueryUsing($desk(QueueType::CxTier2)),
            'noc' => Tab::make('NOC')->modifyQueryUsing($desk(QueueType::Ticket4gNoc)),
            'ip' => Tab::make('IP Team')->modifyQueryUsing($desk(QueueType::TicketIp)),
            'rf' => Tab::make('RF Team')->modifyQueryUsing($desk(QueueType::TicketRf)),
            'core' => Tab::make('Core Team')->modifyQueryUsing($desk(QueueType::TicketCore)),
            'callback' => Tab::make('Customer call-back')->icon(Heroicon::OutlinedPhone)->modifyQueryUsing($desk(QueueType::CxCallback)),
            'sla' => Tab::make('SLA at risk / breached')->icon(Heroicon::OutlinedClock)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereIn('sla_state', [SlaState::Breached->value, SlaState::AtRisk->value])),
            'closed' => Tab::make('Closed')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', true)),
            'all' => Tab::make('All'),
        ];
    }
}
