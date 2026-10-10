<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Concerns\LogsTicketView;
use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\Tickets\TicketActions;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\Tickets\TicketViews;
use App\Filament\Support\OutageLink;
use App\Models\Outage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewTicket extends ViewRecord
{
    use LogsTicketView, RefreshesOnOmsEvents;

    protected static string $resource = TicketResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->ticket_number;
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            // A customer ticket waits while its site visit is worked. The visit is a ticket of its own:
            // assigning the technician, verifying and closing happen on the visit's page.
            Action::make('openVisit')
                ->label(fn (Outage $record) => 'Open site visit '.TicketViews::openVisit($record)?->ticket_number)
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('warning')
                ->tooltip('The site visit is worked on its own page: assign the technician, reschedule, verify the work and close it there.')
                ->url(fn (Outage $record) => OutageLink::url(TicketViews::openVisit($record)))
                ->visible(fn (Outage $record) => TicketViews::openVisit($record) !== null),
            OutageActions::accept(),
            ...TicketActions::primary(),
            ActionGroup::make([
                OutageActions::addUpdate(),
                ...TicketActions::secondary(),
                OutageActions::rejectAssignment(),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    /** Pull fresh state when a real-time event arrives for this ticket. */
    public function onOmsEvent(): void
    {
        $this->getRecord()->refresh();
    }
}
