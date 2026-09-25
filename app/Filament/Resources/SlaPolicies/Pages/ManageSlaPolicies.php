<?php

namespace App\Filament\Resources\SlaPolicies\Pages;

use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSlaPolicies extends ManageRecords
{
    protected static string $resource = SlaPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->modalWidth('5xl')];
    }
}
