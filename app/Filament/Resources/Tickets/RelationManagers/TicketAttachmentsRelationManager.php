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
                // Where and when a geolocation photo was taken: the phone's position, saved with the photo.
                TextColumn::make('latitude')->label('Taken at')->placeholder('—')->wrap()
                    ->state(fn (OutageAttachment $record) => $record->coordinates())
                    ->description(fn (OutageAttachment $record) => collect([$record->place, $record->taken_at?->format('d M Y H:i:s')])->filter()->implode(' · ') ?: null)
                    ->icon(fn (OutageAttachment $record) => $record->hasLocation() ? Heroicon::OutlinedMapPin : null)
                    ->url(fn (OutageAttachment $record) => $record->mapUrl(), shouldOpenInNewTab: true)
                    ->tooltip(fn (OutageAttachment $record) => match ($record->location_source) {
                        'device' => 'The position of the phone when the photo was taken — open it on a map',
                        'photo' => 'Read from the photo file itself — open it on a map',
                        default => null,
                    }),
                TextColumn::make('user.name')->label('Added by')->placeholder('—'),
                TextColumn::make('created_at')->label('Added')->dateTime('d M Y H:i'),
            ])
            ->recordActions([
                Action::make('download')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)->url(fn (OutageAttachment $record) => $record->url()),
            ])
            ->headerActions([TicketActions::attach()->record($this->getOwnerRecord())]);
    }
}
