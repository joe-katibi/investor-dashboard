<?php

namespace App\Filament\Resources\Verticals;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Verticals\Pages\ManageVerticals;
use App\Filament\Support\AuditFields;
use App\Models\Vertical;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class VerticalResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'master_data.manage';

    protected static ?string $model = Vertical::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true),
            TextInput::make('code')->required()->maxLength(30)->unique(ignoreRecord: true)->helperText('Stable identifier, e.g. FTTH or 4G.'),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->searchable(),
                TextColumn::make('services_count')->counts('services')->label('Services'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([AuditFields::activeFilter()])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVerticals::route('/')];
    }
}
