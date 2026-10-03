<?php

namespace App\Filament\Resources\FinalReasons\Pages;

use App\Filament\Resources\FinalReasons\FinalReasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFinalReasons extends ManageRecords
{
    protected static string $resource = FinalReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
