<?php

namespace App\Filament\Resources\ImpactRules;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\ImpactRules\Pages\ManageImpactRules;
use App\Filament\Support\AuditFields;
use App\Models\ImpactRule;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\Vertical;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 4G severity rules: how many eNodeBs / SSGs / ASGs / RSGs (or cells) a fault takes down decides
 * Minor / Major / Critical, per outage type. FTTH keeps rating impact by customers affected under
 * Configuration → Impact / Priority Rules.
 */
class ImpactRuleResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'mobility.configure';

    protected static ?string $viewPermission = 'mobility.view';

    protected static bool $allowsDelete = true;

    protected static ?string $model = ImpactRule::class;

    protected static ?string $slug = '4g/severity-rules';

    protected static ?string $modelLabel = 'severity rule';

    protected static ?string $navigationLabel = 'Severity Rules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsVertical;

    protected static string|UnitEnum|null $navigationGroup = '4G NOC';

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Rule name')->required()->maxLength(150)->columnSpanFull()
                ->placeholder('e.g. Transmission fault – 3 or more SSGs'),
            Select::make('vertical_id')->label('Vertical')->required()->live()
                ->options(fn () => Vertical::query()->active()->orderBy('name')->pluck('name', 'id'))
                ->default(fn () => Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id'))
                ->afterStateUpdated(function (Set $set): void {
                    $set('outage_type_id', null);
                    $set('priority_id', null);
                }),
            Select::make('outage_type_id')->label('Outage type')->placeholder('Any outage type of the vertical')
                ->options(fn (Get $get) => OutageType::query()->where('vertical_id', $get('vertical_id'))->orderBy('sort_order')->pluck('name', 'id')),
            Section::make('When')->schema([
                Select::make('metric')->label('Count of')->options(ImpactRule::METRICS)->required(),
                TextInput::make('min_value')->label('From (inclusive)')->numeric()->minValue(0)->default(1)->required(),
                TextInput::make('max_value')->label('To (inclusive)')->numeric()->minValue(0)->placeholder('No upper limit')->gte('min_value'),
            ])->columns(3)->columnSpanFull(),
            Select::make('priority_id')->label('Then the severity is')->required()
                ->options(fn (Get $get) => Priority::query()->active()->where('vertical_id', $get('vertical_id'))->orderBy('queue_rank')->pluck('name', 'id'))
                ->helperText('When several rules match a ticket, the most severe one wins.'),
            AuditFields::description(),
            AuditFields::activeToggle()->helperText('Inactive rules are ignored.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('outage_type_id')
            ->description('Severity of a 4G ticket is worked out from the sites and transmission nodes selected on it. A transmission node counts everything below it (RSG → ASG → CSG → SSG → eNodeB). The most severe matching rule wins; with no match the default 4G severity applies.')
            ->modifyQueryUsing(fn ($query) => $query->with(['outageType:id,name', 'priority:id,name,color,severity', 'vertical:id,name']))
            ->columns([
                TextColumn::make('outageType.name')->label('Outage type')->placeholder('Any')->sortable()->badge()->color('gray'),
                TextColumn::make('name')->label('Rule')->searchable()->wrap(),
                TextColumn::make('metric')->label('Count of')->formatStateUsing(fn (string $state) => ImpactRule::METRICS[$state] ?? $state),
                TextColumn::make('range')->label('Range')->state(fn (ImpactRule $record) => $record->rangeLabel()),
                TextColumn::make('priority.name')->label('Severity')->badge()->color(fn (ImpactRule $record) => $record->priority?->color ?? 'gray'),
                TextColumn::make('vertical.name')->label('Vertical')->toggleable(isToggledHiddenByDefault: true),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('outage_type_id')->label('Outage type')->options(fn () => OutageType::query()->mobility()->orderBy('sort_order')->pluck('name', 'id')),
                SelectFilter::make('metric')->options(ImpactRule::METRICS),
                AuditFields::activeFilter(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageImpactRules::route('/')];
    }
}
