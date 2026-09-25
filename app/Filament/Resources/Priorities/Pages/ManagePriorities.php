<?php

namespace App\Filament\Resources\Priorities\Pages;

use App\Filament\Resources\Priorities\PriorityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePriorities extends ManageRecords
{
    protected static string $resource = PriorityResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
