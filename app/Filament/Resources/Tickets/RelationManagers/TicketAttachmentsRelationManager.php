<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Filament\Resources\Tickets\TicketActions;
use App\Models\OutageAttachment;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Photos and files: the geolocation and speed-test photos of a visit, and anything else attached. Private downloads. */
class TicketAttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Photos & files';

    public const KINDS = ['geo_photo' => 'Geolocation photo', 'speed_test' => 'Speed test photo', 'evidence' => 'Evidence'];

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name'))
            ->emptyStateHeading('No photos or files')
            ->columns([
                TextColumn::make('kind')->label('Type')->badge()->placeholder('File')
                    ->formatStateUsing(fn (?string $state) => self::KINDS[$state] ?? $state)
                    ->color(fn (?string $state) => $state ? 'success' : 'gray'),
                TextColumn::make('original_name')->label('File')->state(fn (OutageAttachment $record) => $record->name())->icon(Heroicon::OutlinedPaperClip)->wrap(),
                TextColumn::make('note')->placeholder('—')->wrap(),
                TextColumn::make('user.name')->label('Added by')->placeholder('—'),
                TextColumn::make('created_at')->label('Added')->dateTime('d M Y H:i'),
            ])
            ->recordActions([
                Action::make('download')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)->url(fn (OutageAttachment $record) => $record->url()),
            ])
            ->headerActions([TicketActions::attach()->record($this->getOwnerRecord())]);
    }
}
