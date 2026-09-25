<?php

namespace App\Filament\Resources\OutageStatuses\Pages;

use App\Filament\Resources\OutageStatuses\OutageStatusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOutageStatuses extends ManageRecords
{
    protected static string $resource = OutageStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
