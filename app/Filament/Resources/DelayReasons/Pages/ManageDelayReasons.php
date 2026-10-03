<?php

namespace App\Filament\Resources\DelayReasons\Pages;

use App\Filament\Resources\DelayReasons\DelayReasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDelayReasons extends ManageRecords
{
    protected static string $resource = DelayReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
