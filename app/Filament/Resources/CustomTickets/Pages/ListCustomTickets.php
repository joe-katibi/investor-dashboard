<?php

namespace App\Filament\Resources\CustomTickets\Pages;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\CustomTickets\CustomTicketResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListCustomTickets extends ListRecords
{
    use RefreshesOnOmsEvents;

    protected static string $resource = CustomTicketResource::class;

    protected static ?string $title = 'Workflow tickets';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Raise ticket')->icon(Heroicon::OutlinedPlus)];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Open')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', false)),
            'mine' => Tab::make('With me')->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', false)->where('current_assignee_id', Auth::id())),
            'raised' => Tab::make('Raised by me')->modifyQueryUsing(fn (Builder $query) => $query->where('created_by', Auth::id())),
            'closed' => Tab::make('Closed')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', true)),
            'all' => Tab::make('All'),
        ];
    }
}
