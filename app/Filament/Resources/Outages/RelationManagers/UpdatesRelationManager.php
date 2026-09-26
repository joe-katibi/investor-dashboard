<?php

namespace App\Filament\Resources\Outages\RelationManagers;

use App\Filament\Resources\Outages\OutageActions;
use App\Models\OutageUpdate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UpdatesRelationManager extends RelationManager
{
    protected static string $relationship = 'updates';

    protected static ?string $title = 'Timeline & updates';

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
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => OutageUpdate::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'system' => 'gray', 'restoration' => 'success', 'escalation' => 'danger', default => 'info',
                    }),
                TextColumn::make('body')->label('Update')->wrap(),
                TextColumn::make('user.name')->label('By')->placeholder('System')->visibleFrom('md'),
            ])
            ->headerActions([OutageActions::addUpdate()->record($this->getOwnerRecord())]);
    }
}
