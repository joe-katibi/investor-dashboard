<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Filament\Resources\Tickets\TicketViews;
use App\Models\TicketDeskMove;
use App\Services\Tickets\BusinessClock;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Hand-offs between desks, with how long each escalation took to come back (SLA hours). */
class DeskMovesRelationManager extends RelationManager
{
    protected static string $relationship = 'deskMoves';

    protected static ?string $title = 'Escalations & hand-offs';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['movedBy:id,name', 'delayReason:id,name']))
            ->emptyStateHeading('No hand-offs yet')
            ->columns([
                TextColumn::make('moved_at')->label('When')->dateTime('d M Y H:i'),
                TextColumn::make('kind')->label('What')->badge()->formatStateUsing(fn (TicketDeskMove $record) => $record->kindLabel())
                    ->color(fn (string $state) => match ($state) {
                        'route' => 'warning', 'return', 'approve' => 'success', 'decline', 'reopen' => 'danger', default => 'info',
                    }),
                TextColumn::make('from_desk')->label('From')->badge()->placeholder('New ticket')->visibleFrom('md'),
                TextColumn::make('to_desk')->label('To')->badge(),
                TextColumn::make('note')->wrap()->placeholder('—'),
                TextColumn::make('delayReason.name')->label('Reason for delay')->placeholder('—')->toggleable(),
                TextColumn::make('returned_at')->label('Returned')->dateTime('d M H:i')->placeholder('—')
                    ->description(fn (TicketDeskMove $record) => $record->returned_at
                        ? 'after '.TicketViews::duration(app(BusinessClock::class)->minutesBetween($record->moved_at, $record->returned_at)).' (SLA time)' : null),
                TextColumn::make('movedBy.name')->label('By')->placeholder('System')->visibleFrom('lg'),
            ]);
    }
}
