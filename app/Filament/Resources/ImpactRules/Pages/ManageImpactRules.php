<?php

namespace App\Filament\Resources\ImpactRules\Pages;

use App\Filament\Resources\ImpactRules\ImpactRuleResource;
use App\Filament\Resources\Priorities\PriorityResource;
use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageImpactRules extends ManageRecords
{
    protected static string $resource = ImpactRuleResource::class;

    protected static ?string $title = '4G severity rules';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('levels')->label('Severity levels')->icon(Heroicon::OutlinedAdjustmentsVertical)->color('gray')
                ->visible(fn () => PriorityResource::canViewAny())
                ->url(fn () => PriorityResource::getUrl()),
            Action::make('sla')->label('4G SLA targets')->icon(Heroicon::OutlinedClock)->color('gray')
                ->visible(fn () => SlaPolicyResource::canViewAny())
                ->url(fn () => SlaPolicyResource::getUrl()),
            CreateAction::make()->label('New rule'),
        ];
    }
}
