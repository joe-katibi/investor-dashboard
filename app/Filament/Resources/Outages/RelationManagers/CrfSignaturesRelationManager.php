<?php

namespace App\Filament\Resources\Outages\RelationManagers;

use App\Models\ChangeRequestSignature;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** CRF section 6 — sign-offs & authorisations. */
class CrfSignaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'crfSignatures';

    protected static ?string $title = 'CRF sign-off';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->changeRequest !== null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name'))
            ->emptyStateHeading('Not sent for sign-off yet')
            ->emptyStateDescription('NOC adds the signatories when it sends the CRF for sign-off.')
            ->columns([
                TextColumn::make('role_label')->label('Role / authority')->weight('bold')->wrap(),
                TextColumn::make('person')->label('Signatory')
                    ->state(fn (ChangeRequestSignature $record) => $record->displayName())
                    ->description(fn (ChangeRequestSignature $record) => $record->email)->wrap(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ChangeRequestSignature $record) => $record->statusLabel())
                    ->color(fn (string $state) => match ($state) {
                        ChangeRequestSignature::APPROVED => 'success',
                        ChangeRequestSignature::CHANGES_REQUESTED => 'danger',
                        default => 'gray',
                    }),
                ImageColumn::make('signature_image')->label('Signature')->height(36)->visibleFrom('md')
                    ->state(fn (ChangeRequestSignature $record) => $record->status === ChangeRequestSignature::APPROVED ? $record->signature_image : null),
                TextColumn::make('signed_name')->label('Signed as')->placeholder('—')
                    ->description(fn (ChangeRequestSignature $record) => $record->signed_at?->format('d M Y H:i')),
                TextColumn::make('comment')->label('Comment')->wrap()->limit(150)->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('requested_at')->label('Link sent')->dateTime('d M H:i')->placeholder('—')->visibleFrom('lg'),
            ]);
    }
}
