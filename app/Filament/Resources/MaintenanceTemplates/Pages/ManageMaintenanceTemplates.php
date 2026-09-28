<?php

namespace App\Filament\Resources\MaintenanceTemplates\Pages;

use App\Filament\Resources\MaintenanceTemplates\MaintenanceTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMaintenanceTemplates extends ManageRecords
{
    protected static string $resource = MaintenanceTemplateResource::class;

    protected ?string $subheading = 'The e-mails sent during planned maintenance. Several templates can share a trigger (e.g. a second reminder wording); deactivate one to stop it.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->modalWidth('5xl')];
    }
}
