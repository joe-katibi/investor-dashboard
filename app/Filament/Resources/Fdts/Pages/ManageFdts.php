<?php

namespace App\Filament\Resources\Fdts\Pages;

use App\Filament\Resources\Fdts\FdtResource;
use App\Filament\Support\TopologyImportAction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFdts extends ManageRecords
{
    protected static string $resource = FdtResource::class;

    protected function getHeaderActions(): array
    {
        // One spreadsheet (OLT | FDT | SLOT | PORT) fills all four levels, so the import is offered on each screen.
        return [TopologyImportAction::make(), CreateAction::make()];
    }
}
