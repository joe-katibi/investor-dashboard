<?php

namespace App\Filament\Resources\OutageTypes\Pages;

use App\Filament\Resources\OutageTypes\OutageTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOutageTypes extends ManageRecords
{
    protected static string $resource = OutageTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
