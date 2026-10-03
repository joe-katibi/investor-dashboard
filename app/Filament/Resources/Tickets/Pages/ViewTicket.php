<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\Tickets\TicketActions;
use App\Filament\Resources\Tickets\TicketResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewTicket extends ViewRecord
{
    use RefreshesOnOmsEvents;

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
