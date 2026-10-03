<?php

namespace App\Filament\Resources\Ports\Pages;

use App\Filament\Resources\Ports\PortResource;
use App\Filament\Support\TopologyImportAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePorts extends ManageRecords
{
    protected static string $resource = PortResource::class;

    protected function getHeaderActions(): array
    {
        // One spreadsheet (OLT | FDT | SLOT | PORT) fills all four levels, so the import is offered on each screen.
        return [TopologyImportAction::make(), CreateAction::make()];
    }
}
