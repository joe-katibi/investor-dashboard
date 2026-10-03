<?php

namespace App\Filament\Resources\Olts\Pages;

use App\Filament\Resources\Olts\OltResource;
use App\Filament\Support\TopologyImportAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOlts extends ManageRecords
{
    protected static string $resource = OltResource::class;

    protected function getHeaderActions(): array
    {
        // One spreadsheet (OLT | FDT | SLOT | PORT) fills all four levels, so the import is offered on each screen.
        return [TopologyImportAction::make(), CreateAction::make()];
    }
}
