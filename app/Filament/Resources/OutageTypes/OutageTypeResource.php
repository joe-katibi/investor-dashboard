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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
            TextInput::make('prefix')->label('Ticket prefix')->required()->maxLength(10)->regex('/^[A-Za-z0-9]{2,6}(-[A-Za-z0-9]{2,6})?$/')
                ->helperText('Letters / digits, optionally in two parts: OUT → OUT-00000000001, 4G-PWR → 4G-PWR-000001. Each prefix has its own sequence.'),
            Toggle::make('is_planned')->label('Planned maintenance')->hidden(fn (Get $get) => filled($get('workflow'))),
            Toggle::make('is_emergency')->label('Emergency')->hidden(fn (Get $get) => filled($get('workflow'))),
            TextInput::make('sort_order')->numeric()->default(0),
            Section::make('4G NOC workflow')
                ->description('Leave the workflow empty for FTTH / core / system types. A 4G type (fibre cut, battery, generator, microwave…) only needs a workflow, a fault domain and a prefix — no code change. Its severity rules, SLA targets, notification rules and vendor templates can then be set for the type.')
                ->schema([
                    Select::make('workflow')->label('Workflow')->options(OutageType::WORKFLOWS)->placeholder('Standard (not a 4G NOC type)')->live(),
                    Select::make('fault_domain')->label('Fault domain (category for tabs, dashboard and reports)')->options(OutageType::FAULT_DOMAINS)
                        ->visible(fn (Get $get) => filled($get('workflow')))->required(fn (Get $get) => filled($get('workflow'))),
                    Select::make('vertical_id')->label('Vertical')->relationship('vertical', 'name')->preload()
                        ->visible(fn (Get $get) => filled($get('workflow')))->required(fn (Get $get) => filled($get('workflow')))
                        ->helperText('Decides which severity levels and rules apply.'),
                ])->columns(3)->columnSpanFull()->collapsed(fn (?OutageType $record) => blank($record?->workflow)),
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
                TextColumn::make('workflow')->label('Workflow')->badge()->color('info')->placeholder('Standard')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        OutageType::WORKFLOW_MOBILITY_INTERNAL => '4G: NOC → Engineer → NOC',
                        OutageType::WORKFLOW_MOBILITY_VENDOR => '4G: NOC → Vendor → NOC',
                        default => $state,
                    }),
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
