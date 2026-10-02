<?php

namespace App\Filament\Resources\TransmissionNodes\Pages;

use App\Filament\Resources\TransmissionNodes\TransmissionNodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTransmissionNodes extends ManageRecords
{
    protected static string $resource = TransmissionNodeResource::class;

    public function getSubheading(): ?string
    {
        return 'Imported with the 4G workbook under eNodeB Sites → Import 4G topology. Nodes without a site are listed as inactive.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
