<?php

namespace App\Filament\Resources\Outages\RelationManagers;

use App\Models\OutageFollowUp;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** CX Tier II callbacks and site visits on a NOC-rejected ticket. */
class FollowUpsRelationManager extends RelationManager
{
    protected static string $relationship = 'followUps';

    protected static ?string $title = 'CX follow-up';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->noc_rejected_at !== null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name'))
            ->emptyStateHeading('No callback recorded yet')
            ->emptyStateDescription('CX Tier II records the customer callback and any site visit here.')
            ->columns([
                TextColumn::make('called_at')->label('Called')->dateTime('d M Y H:i'),
                TextColumn::make('call_outcome')->label('Outcome')->badge()
                    ->formatStateUsing(fn (string $state) => OutageFollowUp::OUTCOMES[$state] ?? $state)->wrap(),
                TextColumn::make('callback_notes')->label('Notes')->wrap()->limit(200),
                IconColumn::make('site_visit_required')->label('Site visit')->boolean(),
                TextColumn::make('site_visit_at')->label('Visit scheduled')->dateTime('d M Y H:i')->placeholder('—')
                    ->description(fn (OutageFollowUp $record) => $record->site_visit_reference ? 'Ref '.$record->site_visit_reference : null),
                TextColumn::make('site_visit_notes')->label('Site visit action')->wrap()->limit(150)->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('user.name')->label('By'),
                IconColumn::make('closed_ticket')->label('Closed')->boolean()->visibleFrom('md'),
            ]);
    }
}
