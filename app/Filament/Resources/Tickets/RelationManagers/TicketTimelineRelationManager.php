<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\Tickets\TicketActions;
use App\Models\OutageUpdate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Everything that happened on the ticket, newest first: hand-offs, calls, SMS to the customer, updates, closure. */
class TicketTimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'updates';

    protected static ?string $title = 'Timeline';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M H:i')->sortable(),
                TextColumn::make('type')->badge()->visibleFrom('md')->formatStateUsing(fn (string $state) => OutageUpdate::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'system' => 'gray', 'restoration' => 'success', 'escalation' => 'danger', default => 'info',
                    }),
                TextColumn::make('body')->label('Entry')->wrap(),
                TextColumn::make('user.name')->label('By')->placeholder('System')->visibleFrom('md'),
            ])
            ->filters([SelectFilter::make('type')->options(OutageUpdate::TYPES)])
            ->headerActions([
                OutageActions::addUpdate()->record($this->getOwnerRecord()),
                TicketActions::followUp()->record($this->getOwnerRecord()),
            ]);
    }
}
