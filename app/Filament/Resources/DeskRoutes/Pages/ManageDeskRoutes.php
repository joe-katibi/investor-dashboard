<?php

namespace App\Filament\Resources\DeskRoutes\Pages;

use App\Filament\Resources\DeskRoutes\DeskRouteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDeskRoutes extends ManageRecords
{
    protected static string $resource = DeskRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
