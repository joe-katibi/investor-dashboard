<?php

namespace App\Filament\Resources\MajorOutageTypes\Pages;

use App\Filament\Resources\MajorOutageTypes\MajorOutageTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMajorOutageTypes extends ManageRecords
{
    protected static string $resource = MajorOutageTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
