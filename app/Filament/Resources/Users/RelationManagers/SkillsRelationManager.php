<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SkillsRelationManager extends RelationManager
{
    protected static string $relationship = 'skills';

    protected static ?string $title = 'Skills (FIFO skill-based routing)';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) Auth::user()?->can('workforce.manage');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('skill')->maxLength(100),
            Select::make('service_id')->relationship('service', 'name')->searchable()->preload()->helperText('Qualifies for outages affecting this service.'),
            Select::make('vertical_id')->relationship('vertical', 'name')->helperText('Or: qualifies for any outage in this vertical.'),
            TextInput::make('proficiency')->numeric()->minValue(1)->maxValue(5),
            Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('skill')->placeholder('—'),
                TextColumn::make('service.name')->placeholder('Any service'),
                TextColumn::make('vertical.name')->placeholder('—'),
                TextColumn::make('proficiency')->placeholder('—'),
                IconColumn::make('is_active')->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
