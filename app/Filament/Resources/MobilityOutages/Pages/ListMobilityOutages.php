<?php

namespace App\Filament\Resources\MobilityOutages\Pages;

use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\MobilityOutages\MobilityOutageResource;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\Priority;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListMobilityOutages extends ListRecords
{
    use RefreshesOnOmsEvents;

    protected static string $resource = MobilityOutageResource::class;

    protected static ?string $title = '4G outages';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Report 4G outage')->icon(Heroicon::OutlinedPlus),
        ];
    }

    public function getTabs(): array
    {
        $open = fn (Builder $query) => $query->where('is_closed', false);
        $pending = fn () => OutageStatus::query()
            ->whereIn('code', [StatusCode::MobilityResolvedPendingNoc->value, StatusCode::MobilityVendorResolvedPendingNoc->value])->pluck('id')->all();

        return [
            'active' => Tab::make('Open')->modifyQueryUsing($open),
            'mine' => Tab::make('My assigned tickets')->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->where(fn (Builder $q) => $q->where('current_assignee_id', Auth::id())->orWhere('technician_id', Auth::id()))),
            'enodeb' => Tab::make('eNodeB')->modifyQueryUsing(fn (Builder $query) => $open($query)->ofDomain(OutageType::DOMAIN_ENODEB)),
            'transmission' => Tab::make('Transmission')->modifyQueryUsing(fn (Builder $query) => $open($query)->ofDomain(OutageType::DOMAIN_TRANSMISSION)),
            'power' => Tab::make('Power')->modifyQueryUsing(fn (Builder $query) => $open($query)->ofDomain(OutageType::DOMAIN_POWER)),
            'vendor' => Tab::make('Vendor tickets')->icon(Heroicon::OutlinedBuildingOffice2)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->where('workflow', OutageType::WORKFLOW_MOBILITY_VENDOR)),
            'pending' => Tab::make('Pending NOC confirmation')->icon(Heroicon::OutlinedShieldCheck)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereIn('status_id', $pending() ?: [0])),
            'critical' => Tab::make('Critical')->modifyQueryUsing(fn (Builder $query) => $open($query)->whereHas('priority', fn ($p) => $p->whereIn('code', Priority::CRITICAL_CODES))),
            'sla' => Tab::make('SLA monitoring')->icon(Heroicon::OutlinedClock)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereIn('sla_state', [SlaState::Breached->value, SlaState::AtRisk->value])),
            'closed' => Tab::make('Closed')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', true)),
            'all' => Tab::make('All'),
        ];
    }
}
