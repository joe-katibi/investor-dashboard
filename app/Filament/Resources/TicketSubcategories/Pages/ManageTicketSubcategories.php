<?php

namespace App\Filament\Resources\TicketSubcategories\Pages;

use App\Filament\Resources\TicketSubcategories\TicketSubcategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTicketSubcategories extends ManageRecords
{
    protected static string $resource = TicketSubcategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
