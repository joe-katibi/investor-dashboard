<?php

namespace App\Filament\Resources\Slots\Pages;

use App\Filament\Resources\Slots\SlotResource;
use App\Filament\Support\TopologyImportAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSlots extends ManageRecords
{
    protected static string $resource = SlotResource::class;

    protected function getHeaderActions(): array
    {
        // One spreadsheet (OLT | FDT | SLOT | PORT) fills all four levels, so the import is offered on each screen.
        return [TopologyImportAction::make(), CreateAction::make()];
    }
}
