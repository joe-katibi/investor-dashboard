<?php

namespace App\Filament\Resources\MobilityOutages\Pages;

use App\Filament\Concerns\LogsTicketView;
use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\MobilityOutages\MobilityActions;
use App\Filament\Resources\MobilityOutages\MobilityOutageResource;
use App\Filament\Resources\Outages\OutageActions;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewMobilityOutage extends ViewRecord
{
    use LogsTicketView, RefreshesOnOmsEvents;

    protected static string $resource = MobilityOutageResource::class;

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
            ...MobilityActions::primary(),
            ActionGroup::make([
                OutageActions::addUpdate(),
                ...MobilityActions::secondary(),
                OutageActions::rejectAssignment(),
                EditAction::make()->icon(Heroicon::OutlinedPencilSquare),
            ])->label('More')->icon(Heroicon::OutlinedEllipsisVertical)->button()->color('gray'),
        ];
    }

    /** Pull fresh state when a real-time event arrives for this outage. */
    public function onOmsEvent(): void
    {
        $this->getRecord()->refresh();
    }
}
