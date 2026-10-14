<?php

namespace App\Filament\Support;

use App\Models\Vertical;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

/**
 * "Vertical" (FTTH, 4G, …) on the screens of things that belong to one: ticket categories,
 * customers, final reasons, reasons for delay.
 */
final class VerticalFields
{
    public static function select(string $help = 'Offered on tickets of this vertical only.'): Select
    {
        return Select::make('vertical_id')->label('Vertical')->required()
            ->options(fn () => Vertical::query()->active()->orderBy('id')->pluck('name', 'id'))
            ->default(fn () => Vertical::idFor(Vertical::FTTH))
            ->helperText($help);
    }

    public static function column(): TextColumn
    {
        return TextColumn::make('vertical.name')->label('Vertical')->badge()->placeholder('All')
            ->color(fn ($record) => $record?->vertical?->code === Vertical::MOBILITY_4G ? 'info' : 'gray');
    }

    public static function filter(): SelectFilter
    {
        return SelectFilter::make('vertical_id')->label('Vertical')->options(fn () => Vertical::query()->orderBy('id')->pluck('name', 'id'));
    }
}
