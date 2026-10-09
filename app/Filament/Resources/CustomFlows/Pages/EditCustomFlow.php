<?php

namespace App\Filament\Resources\CustomFlows\Pages;

use App\Filament\Resources\CustomFlows\CustomFlowResource;
use Filament\Resources\Pages\EditRecord;

class EditCustomFlow extends EditRecord
{
    protected static string $resource = CustomFlowResource::class;

    public function getSubheading(): ?string
    {
        $open = $this->getRecord()->tickets()->whereHas('outage', fn ($outage) => $outage->where('is_closed', false))->count();

        return 'Version '.$this->getRecord()->version.'. A change applies to tickets raised from now on'
            .($open > 0 ? " — the {$open} open ".str('ticket')->plural($open).' of this workflow keep the steps they started with.' : '.');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return CustomFlowResource::getUrl('index');
    }
}
