<?php

namespace App\Filament\Resources\OutageTypes;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\OutageTypes\Pages\ManageOutageTypes;
use App\Filament\Support\AuditFields;
use App\Models\OutageType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class OutageTypeResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'master_data.manage';

    protected static ?string $model = OutageType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 13;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100),
            TextInput::make('code')->required()->maxLength(30)->unique(ignoreRecord: true)->alphaDash(),
            Select::make('major_outage_type_id')->label('Major outage type')->relationship('majorOutageType', 'name')->required()->preload(),
            TextInput::make('prefix')->label('Ticket prefix')->required()->maxLength(10)->regex('/^[A-Za-z]{2,10}$/')
                ->helperText('Letters only, e.g. OUT → OUT-00000000001. Each prefix has its own sequence.'),
            Toggle::make('is_planned')->label('Planned maintenance'),
            Toggle::make('is_emergency')->label('Emergency'),
            TextInput::make('sort_order')->numeric()->default(0),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('majorOutageType.name')->label('Major type')->badge(),
                TextColumn::make('prefix')->badge()->color('gray'),
                IconColumn::make('is_planned')->label('Planned')->boolean(),
                IconColumn::make('is_emergency')->label('Emergency')->boolean(),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('major_outage_type_id')->relationship('majorOutageType', 'name')->label('Major type'),
                AuditFields::activeFilter(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOutageTypes::route('/')];
    }
}
