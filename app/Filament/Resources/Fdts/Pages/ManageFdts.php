<?php

namespace App\Filament\Resources\Fdts\Pages;

use App\Filament\Resources\Fdts\FdtResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFdts extends ManageRecords
{
    protected static string $resource = FdtResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
