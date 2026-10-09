<?php

namespace App\Filament\Resources\CustomFlows\Pages;

use App\Filament\Resources\CustomFlows\CustomFlowResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomFlow extends CreateRecord
{
    protected static string $resource = CustomFlowResource::class;

    protected static ?string $title = 'New workflow';

    protected function getRedirectUrl(): string
    {
        return CustomFlowResource::getUrl('index');
    }
}
