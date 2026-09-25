<?php

namespace App\Filament\Resources\Services;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Services\Pages\ManageServices;
use App\Filament\Support\AuditFields;
use App\Models\Service;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ServiceResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'master_data.manage';

    protected static ?string $model = Service::class;

    protected static ?string $modelLabel = 'affected service';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Service name')->required()->maxLength(150),
            Select::make('vertical_id')->relationship('vertical', 'name')->preload()->searchable()
                ->helperText('Leave empty for cross-vertical services (CRM, Dialer, core transit...).'),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('vertical.name')->badge()->placeholder('All verticals')->sortable(),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('vertical_id')->relationship('vertical', 'name')->label('Vertical'),
                AuditFields::activeFilter(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageServices::route('/')];
    }
}
