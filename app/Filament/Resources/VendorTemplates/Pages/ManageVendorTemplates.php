<?php

namespace App\Filament\Resources\VendorTemplates\Pages;

use App\Filament\Resources\VendorTemplates\VendorTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVendorTemplates extends ManageRecords
{
    protected static string $resource = VendorTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->modalWidth('5xl')];
    }
}
