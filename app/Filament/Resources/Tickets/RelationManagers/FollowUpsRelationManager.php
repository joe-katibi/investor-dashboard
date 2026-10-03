<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Filament\Resources\Tickets\TicketActions;
use App\Models\TicketFollowUp;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Calls made to the customer about this ticket. */
class FollowUpsRelationManager extends RelationManager
{
    protected static string $relationship = 'ticketFollowUps';

    protected static ?string $title = 'Customer calls';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name'))
            ->emptyStateHeading('No calls logged')
            ->columns([
                TextColumn::make('called_at')->label('Called at')->dateTime('d M Y H:i'),
                TextColumn::make('kind')->label('Call')->badge()->color('gray')->formatStateUsing(fn (string $state) => TicketFollowUp::KINDS[$state] ?? $state),
                TextColumn::make('outcome')->badge()->formatStateUsing(fn (TicketFollowUp $record) => $record->outcomeLabel())
                    ->color(fn (TicketFollowUp $record) => match (true) {
                        $record->outcome === 'confirmed' => 'success', $record->outcome === 'not_resolved' => 'danger', ! $record->reached() => 'warning', default => 'info',
                    })->wrap(),
                TextColumn::make('notes')->wrap()->placeholder('—'),
                TextColumn::make('user.name')->label('Agent')->placeholder('—'),
            ])
            ->headerActions([TicketActions::followUp()->record($this->getOwnerRecord())]);
    }
}
