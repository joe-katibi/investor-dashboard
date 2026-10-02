<?php

namespace App\Filament\Resources\MobilityOutages\RelationManagers;

use App\Enums\SlaState;
use App\Models\OutageReopening;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Who reopened the ticket, when and why — with the closure that was undone. */
class ReopeningsRelationManager extends RelationManager
{
    protected static string $relationship = 'reopenings';

    protected static ?string $title = 'Reopening history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->reopen_count > 0;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name', 'previousStatus:id,name', 'previousClosedBy:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('Reopened')->dateTime('d M Y H:i')
                    ->description(fn (OutageReopening $record) => $record->user?->name ? 'by '.$record->user->name : null),
                TextColumn::make('reason')->wrap(),
                TextColumn::make('previousStatus.name')->label('Was')->badge()->color('gray')
                    ->description(fn (OutageReopening $record) => collect([$record->previous_closed_at?->format('d M H:i'), $record->previousClosedBy?->name])->filter()->implode(' · ')),
                TextColumn::make('previous_sla_state')->label('SLA then')->badge()->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => SlaState::tryFrom((string) $state)?->getLabel() ?? $state),
                TextColumn::make('previous_root_cause')->label('Previous root cause')->wrap()->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('previous_resolution')->label('Previous resolution')->wrap()->placeholder('—')->visibleFrom('lg'),
            ]);
    }
}
