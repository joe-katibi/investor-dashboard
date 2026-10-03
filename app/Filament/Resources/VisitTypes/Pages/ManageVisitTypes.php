<?php

namespace App\Filament\Resources\VisitTypes\Pages;

use App\Filament\Resources\VisitTypes\VisitTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVisitTypes extends ManageRecords
{
    protected static string $resource = VisitTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
