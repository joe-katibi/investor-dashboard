<?php

namespace App\Filament\Resources\CustomTickets\Pages;

use App\Filament\Concerns\LogsTicketView;
use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\CustomTickets\CustomTicketActions;
use App\Filament\Resources\CustomTickets\CustomTicketResource;
use App\Filament\Resources\Outages\OutageActions;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCustomTicket extends ViewRecord
{
    use LogsTicketView, RefreshesOnOmsEvents;

    protected static string $resource = CustomTicketResource::class;

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
            OutageActions::accept(),
            CustomTicketActions::complete(),
            CustomTicketActions::sendBack(),
            ActionGroup::make([
                OutageActions::addUpdate(),
                CustomTicketActions::assign(),
                OutageActions::rejectAssignment(),
                CustomTicketActions::cancel(),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    /** Pull fresh state when a real-time event arrives for this ticket. */
    public function onOmsEvent(): void
    {
        $this->getRecord()->refresh();
    }
}
