<?php

namespace App\Filament\Resources\VisitTypes;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\VisitTypes\Pages\ManageVisitTypes;
use App\Filament\Support\AuditFields;
use App\Models\Priority;
use App\Models\Vertical;
use App\Models\VisitType;
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
use Filament\Tables\Table;
use UnitEnum;

/** Kinds of site visit. The prefix numbers the visits (INS-1, WiE-1, SUP-1, SHI-1), each with its own counter. */
class VisitTypeResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'ticket.configure';

    protected static ?string $model = VisitType::class;

    protected static ?string $slug = 'customer-care/visit-types';

    protected static ?string $navigationLabel = 'Visit Types';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 23;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)->unique(ignoreRecord: true),
            TextInput::make('code_prefix')->label('Number prefix')->required()->maxLength(10)->alphaDash()->unique(ignoreRecord: true)
                ->helperText('Visits are numbered PREFIX-1, PREFIX-2… Changing it starts a new series.'),
            TextInput::make('default_sla_minutes')->label('SLA target (minutes)')->numeric()->minValue(1)->maxValue(100000)->placeholder('Use the default')
                ->helperText('Counted on the ticket SLA clock. 180 = 3 hours, 4320 = 72 hours.'),
            Select::make('priority_id')->label('Priority')->placeholder('None')
                ->options(fn () => Priority::query()->active()->where('vertical_id', Vertical::query()->where('code', Vertical::FTTH)->value('id'))->orderBy('queue_rank')->pluck('name', 'id')),
            Toggle::make('requires_evidence')->label('Needs evidence to complete')->default(true)
                ->helperText('FAT number, geolocation photo and speed-test photo before the technician can complete the visit.'),
            TextInput::make('sort_order')->label('Order in lists')->numeric()->default(0)->minValue(0)->maxValue(999),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('code_prefix')->label('Prefix')->badge()->color('info'),
                TextColumn::make('default_sla_minutes')->label('SLA (min)')->placeholder('Default'),
                TextColumn::make('priority.name')->label('Priority')->placeholder('—')->visibleFrom('md'),
                IconColumn::make('requires_evidence')->label('Evidence')->boolean(),
                TextColumn::make('description')->wrap()->placeholder('—')->visibleFrom('lg'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([AuditFields::activeFilter()])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVisitTypes::route('/')];
    }
}
