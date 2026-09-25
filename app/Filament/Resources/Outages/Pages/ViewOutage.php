<?php

namespace App\Filament\Resources\Outages\Pages;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\Outages\OutageResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewOutage extends ViewRecord
{
    use RefreshesOnOmsEvents;

    protected static string $resource = OutageResource::class;

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
            ...OutageActions::primary(),
            ActionGroup::make([
                ...OutageActions::secondary(),
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
