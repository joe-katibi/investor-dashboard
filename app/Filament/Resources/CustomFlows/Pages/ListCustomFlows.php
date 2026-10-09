<?php

namespace App\Filament\Resources\CustomFlows\Pages;

use App\Filament\Resources\CustomFlows\CustomFlowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomFlows extends ListRecords
{
    protected static string $resource = CustomFlowResource::class;

    public function getSubheading(): ?string
    {
        return 'Workflows built here: a name, a ticket prefix and steps in order. Their tickets are raised and worked under Operations → Workflow Tickets.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New workflow')];
    }
}
