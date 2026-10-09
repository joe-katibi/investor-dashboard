<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Users\Widgets\UserStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /** Above the list: how many accounts, how many active, who has signed in on the website and in the app. */
    protected function getHeaderWidgets(): array
    {
        return [UserStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
