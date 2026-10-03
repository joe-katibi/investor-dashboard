<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\OutageStatus;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListTickets extends ListRecords
{
    use RefreshesOnOmsEvents;

    protected static string $resource = TicketResource::class;

    protected static ?string $title = 'Customer tickets';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Raise ticket')->icon(Heroicon::OutlinedPlus)];
    }

    public function getTabs(): array
    {
        $open = fn (Builder $query) => $query->where('is_closed', false);
        $desk = fn (QueueType ...$desks) => fn (Builder $query) => $open($query)->whereIn('current_queue', array_map(fn (QueueType $d) => $d->value, $desks));
        $status = fn (StatusCode $code) => OutageStatus::query()->where('code', $code->value)->value('id') ?? 0;

        return [
            'open' => Tab::make('Open')->modifyQueryUsing($open),
            'mine' => Tab::make('Assigned to me')->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->where('current_assignee_id', Auth::id())),
            'raised' => Tab::make('Raised by me')->modifyQueryUsing(fn (Builder $query) => $open($query)->where('created_by', Auth::id())),
            'tier2' => Tab::make('CX Tier II')->modifyQueryUsing($desk(QueueType::CxTier2)),
            'billing' => Tab::make('Billing')->modifyQueryUsing($desk(QueueType::Billing, QueueType::BillingManager)),
            'approval' => Tab::make('Approvals')->icon(Heroicon::OutlinedCheckBadge)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->where('status_id', $status(StatusCode::TicketPendingApproval))),
            'noc' => Tab::make('NOC')->modifyQueryUsing($desk(QueueType::TicketNoc)),
            'callback' => Tab::make('Customer call-back')->icon(Heroicon::OutlinedPhone)->modifyQueryUsing($desk(QueueType::CxCallback)),
            'visit' => Tab::make('Waiting for a site visit')->icon(Heroicon::OutlinedMapPin)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->where('status_id', $status(StatusCode::TicketSiteVisit))),
            'sla' => Tab::make('SLA at risk / breached')->icon(Heroicon::OutlinedClock)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereIn('sla_state', [SlaState::Breached->value, SlaState::AtRisk->value])),
            'closed' => Tab::make('Closed')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', true)),
            'all' => Tab::make('All'),
        ];
    }
}
