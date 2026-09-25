<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;

/**
 * Shared building blocks for the created by / edited by audit fields every master record
 * carries (spec §6 / §21), plus the common active + description fields.
 */
final class AuditFields
{
    /** @return array<int, TextColumn> */
    public static function columns(): array
    {
        return [
            TextColumn::make('creator.name')->label('Created by')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('created_at')->label('Created at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('editor.name')->label('Edited by')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('updated_at')->label('Updated at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function activeColumn(): IconColumn
    {
        return IconColumn::make('is_active')->label('Active')->boolean()->sortable();
    }

    public static function activeFilter(): TernaryFilter
    {
        return TernaryFilter::make('is_active')->label('Active');
    }

    public static function activeToggle(): Toggle
    {
        return Toggle::make('is_active')
            ->label('Active')
            ->default(true)
            ->helperText('Inactive records stay on historical outages but cannot be selected for new ones.');
    }

    public static function description(): Textarea
    {
        return Textarea::make('description')->rows(2)->maxLength(2000)->columnSpanFull();
    }

    public static function section(): Section
    {
        return Section::make('Audit')
            ->schema([
                TextEntry::make('creator.name')->label('Created by')->placeholder('System'),
                TextEntry::make('created_at')->label('Created at')->dateTime(),
                TextEntry::make('editor.name')->label('Edited by')->placeholder('—'),
                TextEntry::make('updated_at')->label('Updated at')->dateTime(),
            ])
            ->columns(4)
            ->collapsible();
    }
}
