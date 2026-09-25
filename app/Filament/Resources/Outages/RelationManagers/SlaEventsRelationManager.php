<?php

namespace App\Filament\Resources\Outages\RelationManagers;

use App\Models\SlaEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SlaEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'slaEvents';

    protected static ?string $title = 'SLA events';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('slaPolicy:id,name'))
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y H:i:s'),
                TextColumn::make('event')->badge()->color(fn (string $state) => match ($state) {
                    'breached', 'missed', 'escalated' => 'danger', 'pre_breach' => 'warning', 'met' => 'success', default => 'gray',
                }),
                TextColumn::make('slaPolicy.name')->label('Policy')->placeholder('Default'),
                TextColumn::make('meta')->state(fn (SlaEvent $record) => collect($record->meta ?? [])->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode(', ', $v) : $v))->implode(' · '))->wrap(),
                TextColumn::make('trigger')->color('gray'),
            ]);
    }
}
