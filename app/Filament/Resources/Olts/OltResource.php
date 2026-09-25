<?php

namespace App\Filament\Resources\Olts;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Fdts\FdtResource;
use App\Filament\Resources\Olts\Pages\ManageOlts;
use App\Filament\Support\AuditFields;
use App\Models\Olt;
use BackedEnum;
use Filament\Actions\Action;
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

class OltResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'topology.manage';

    protected static ?string $viewPermission = 'outage.view';

    protected static ?string $model = Olt::class;

    protected static ?string $modelLabel = 'OLT';

    protected static ?string $pluralModelLabel = 'OLTs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Topology';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Name / identifier')->required()->maxLength(120)->unique(ignoreRecord: true),
            TextInput::make('code')->maxLength(60),
            TextInput::make('location')->maxLength(255),
            Select::make('vertical_id')->relationship('vertical', 'name')->preload(),
            Select::make('teams')->label('Covering teams')->relationship('teams', 'name')->multiple()->preload()
                ->helperText('Optional FIFO coverage: teams listed here are preferred for outages on this OLT.'),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->placeholder('—'),
                TextColumn::make('location')->searchable()->placeholder('—'),
                TextColumn::make('fdts_count')->counts('fdts')->label('FDTs'),
                TextColumn::make('outages_count')->counts('outages')->label('Outages')->sortable(),
                TextColumn::make('teams.name')->label('Coverage')->badge()->color('gray'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([SelectFilter::make('vertical_id')->relationship('vertical', 'name'), AuditFields::activeFilter()])
            ->recordActions([
                Action::make('fdts')->label('FDTs')->icon(Heroicon::OutlinedArrowRight)->color('gray')
                    ->url(fn (Olt $record) => FdtResource::getUrl('index', ['filters' => ['olt_id' => ['value' => $record->id]]])),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOlts::route('/')];
    }
}
