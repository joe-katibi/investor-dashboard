<?php

namespace App\Filament\Resources\Priorities;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\OutageStatuses\OutageStatusResource;
use App\Filament\Resources\Priorities\Pages\ManagePriorities;
use App\Filament\Support\AuditFields;
use App\Models\Priority;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PriorityResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'master_data.manage';

    protected static ?string $model = Priority::class;

    protected static ?string $navigationLabel = 'Impact / Priority Rules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsVertical;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 15;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(50)->unique(ignoreRecord: true),
            TextInput::make('code')->required()->maxLength(30)->unique(ignoreRecord: true)->alphaDash(),
            Select::make('color')->options(OutageStatusResource::COLORS)->default('gray'),
            TextInput::make('severity')->numeric()->minValue(1)->required()
                ->helperText('Higher = more severe. Used when several impact rules match.'),
            Section::make('Impact rule — total customers affected')->schema([
                TextInput::make('min_customers')->numeric()->minValue(0)->label('From (inclusive)'),
                TextInput::make('max_customers')->numeric()->minValue(0)->label('To (inclusive)')->placeholder('No upper limit')
                    ->gte('min_customers'),
                Toggle::make('is_default')->label('Default when no rule matches'),
            ])->columns(3)->columnSpanFull(),
            Section::make('FIFO queue order')->schema([
                TextInput::make('queue_rank')->numeric()->minValue(1)->required()
                    ->helperText('Rank 1 is served first. Default: Critical 1 → High 2 → Medium 3 → Low 4. FIFO applies within each rank.'),
            ])->columnSpanFull(),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('queue_rank')
            ->description('Priority is evaluated automatically from total customers affected when an outage is created or updated.')
            ->columns([
                TextColumn::make('name')->badge()->color(fn (Priority $record) => $record->color),
                TextColumn::make('min_customers')->label('Customers from')->placeholder('—'),
                TextColumn::make('max_customers')->label('to')->placeholder('∞'),
                TextColumn::make('severity')->sortable(),
                TextColumn::make('queue_rank')->label('FIFO rank')->sortable(),
                IconColumn::make('is_default')->label('Default')->boolean(),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePriorities::route('/')];
    }
}
