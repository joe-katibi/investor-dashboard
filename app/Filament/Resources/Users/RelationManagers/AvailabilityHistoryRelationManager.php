<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AvailabilityHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'availabilityHistory';

    protected static ?string $title = 'Availability history';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('availability_status')->label('Status')->badge(),
                TextColumn::make('effective_from')->dateTime('Y-m-d H:i:s'),
                TextColumn::make('effective_to')->dateTime('Y-m-d H:i:s')->placeholder('Current'),
                TextColumn::make('reason')->placeholder('—'),
                TextColumn::make('changedBy.name')->label('Changed by')->placeholder('Self / system'),
            ]);
    }
}
