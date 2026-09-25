<?php

namespace App\Filament\Resources\QueueDefinitions\Pages;

use App\Filament\Resources\QueueDefinitions\QueueDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageQueueDefinitions extends ManageRecords
{
    protected static string $resource = QueueDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->modalWidth('4xl')];
    }
}
