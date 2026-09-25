<?php

namespace App\Filament\Resources\OutageStatuses;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\OutageStatuses\Pages\ManageOutageStatuses;
use App\Filament\Support\AuditFields;
use App\Models\OutageStatus;
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

class OutageStatusResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'master_data.manage';

    protected static ?string $model = OutageStatus::class;

    protected static ?string $modelLabel = 'status';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 14;

    protected static ?string $recordTitleAttribute = 'name';

    public const COLORS = ['gray' => 'Gray', 'info' => 'Blue', 'primary' => 'Amber', 'success' => 'Green', 'warning' => 'Orange', 'danger' => 'Red'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('color')->options(self::COLORS)->default('gray')->required(),
            TextInput::make('code')->label('Workflow code')->disabled()->dehydrated(false)
                ->placeholder('Custom status')
                ->helperText('Workflow statuses are driven by the engine; their code cannot change.'),
            TextInput::make('sort_order')->numeric()->default(0),
            AuditFields::description(),
            Section::make('Behaviour')->schema([
                Toggle::make('prompt_final_reason')->label('Prompt for Final Reason')
                    ->helperText('Selecting this status requires a Final Reason / RFO.'),
                Toggle::make('is_manual_selectable')->label('Operators may set this status')
                    ->helperText('Shown in the "Set status" action (e.g. Partial Restore, Vendor Escalated).'),
                Toggle::make('pauses_sla')->label('Pauses the SLA clock'),
                Toggle::make('is_closed')->label('Closed state')->helperText('Counts as closed / no longer active.')
                    ->disabled(fn (?OutageStatus $record) => (bool) $record?->is_system),
                Toggle::make('is_resolved')->label('Resolved state')
                    ->disabled(fn (?OutageStatus $record) => (bool) $record?->is_system),
                AuditFields::activeToggle()->disabled(fn (?OutageStatus $record) => (bool) $record?->is_system),
            ])->columns(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')->badge()->color(fn (OutageStatus $record) => $record->color)->searchable(),
                TextColumn::make('code')->placeholder('custom')->color('gray'),
                IconColumn::make('prompt_final_reason')->label('Final reason')->boolean(),
                IconColumn::make('is_manual_selectable')->label('Manual')->boolean(),
                IconColumn::make('pauses_sla')->label('Pauses SLA')->boolean(),
                IconColumn::make('is_closed')->label('Closed')->boolean(),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageOutageStatuses::route('/')];
    }
}
