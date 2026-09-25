<?php

namespace App\Filament\Resources\Olts\Pages;

use App\Filament\Resources\Olts\OltResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageOlts extends ManageRecords
{
    protected static string $resource = OltResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
