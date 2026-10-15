<?php

namespace App\Filament\Resources\SiteVisits\Pages;

use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\SiteVisits\SiteVisitResource;
use App\Models\OutageStatus;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListSiteVisits extends ListRecords
{
    use RefreshesOnOmsEvents;

    protected static string $resource = SiteVisitResource::class;

    protected static ?string $title = 'Site visits';

    /** The list has its own switch ("visit.view"); a single visit opens for anyone who may view tickets. */
    protected function authorizeAccess(): void
    {
        abort_unless(SiteVisitResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Book site visit')->icon(Heroicon::OutlinedPlus)];
    }

    public function getTabs(): array
    {
        $open = fn (Builder $query) => $query->where('is_closed', false);
        $desk = fn (QueueType ...$desks) => fn (Builder $query) => $open($query)->whereIn('current_queue', array_map(fn (QueueType $d) => $d->value, $desks));
        $status = fn (StatusCode ...$codes) => OutageStatus::query()->whereIn('code', array_map(fn (StatusCode $c) => $c->value, $codes))->pluck('id')->all() ?: [0];

        return [
            'open' => Tab::make('Open')->modifyQueryUsing($open),
            'mine' => Tab::make('Assigned to me')->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->where(fn (Builder $q) => $q->where('current_assignee_id', Auth::id())->orWhere('technician_id', Auth::id()))),
            'dispatch' => Tab::make('With dispatch')->icon(Heroicon::OutlinedTruck)->modifyQueryUsing($desk(QueueType::VisitDispatch)),
            'today' => Tab::make('Booked today')->icon(Heroicon::OutlinedCalendarDays)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereHas('ticket', fn (Builder $t) => $t->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()]))),
            'no_team' => Tab::make('Pending without team')
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereIn('status_id', $status(StatusCode::VisitPendingWithoutTeam))),
            'field' => Tab::make('In the field')
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereIn('status_id', $status(StatusCode::VisitPendingScheduled, StatusCode::VisitInProgress))),
            'escalated' => Tab::make('Escalated')->icon(Heroicon::OutlinedArrowUpCircle)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereIn('status_id', $status(StatusCode::VisitPendingEscalated))),
            'verify' => Tab::make('To verify')->icon(Heroicon::OutlinedShieldCheck)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereIn('status_id', $status(StatusCode::VisitPendingVerification))),
            'customer' => Tab::make('CX Field Support')->icon(Heroicon::OutlinedPhone)->modifyQueryUsing($desk(QueueType::CxFieldSupport)),
            'follow_up' => Tab::make('Follow-up due')
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereHas('ticket', fn (Builder $t) => $t->whereNotNull('follow_up_due_at'))),
            // Closed visits whose 72-hour call is due (CX Follow-up – Site Visit).
            'aftercare' => Tab::make('72-hour follow-up')->icon(Heroicon::OutlinedPhoneArrowUpRight)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('ticket', fn (Builder $t) => $t->whereNotNull('aftercare_due_at')->whereNull('aftercare_done_at'))),
            'sla' => Tab::make('SLA at risk / breached')->icon(Heroicon::OutlinedClock)
                ->modifyQueryUsing(fn (Builder $query) => $open($query)->whereIn('sla_state', [SlaState::Breached->value, SlaState::AtRisk->value])),
            'closed' => Tab::make('Closed')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', true)),
            'all' => Tab::make('All'),
        ];
    }
}
