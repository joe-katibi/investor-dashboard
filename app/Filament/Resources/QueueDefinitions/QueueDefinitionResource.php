<?php

namespace App\Filament\Resources\QueueDefinitions;

use App\Enums\QueueType;
use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\QueueDefinitions\Pages\ManageQueueDefinitions;
use App\Filament\Support\AuditFields;
use App\Models\QueueDefinition;
use App\Support\Permissions;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class QueueDefinitionResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'config.admin';

    protected static ?string $model = QueueDefinition::class;

    protected static ?string $modelLabel = 'FIFO queue';

    protected static ?string $navigationLabel = 'FIFO Queues';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120),
            Select::make('queue_type')->options(QueueType::class)->required(),
            Section::make('Routing')->description('Optional matchers — the most specific active queue of a type receives the outage.')->schema([
                Select::make('major_outage_type_id')->relationship('majorOutageType', 'name')->label('Major type')->placeholder('Any'),
                Select::make('vertical_id')->relationship('vertical', 'name')->placeholder('Any'),
            ])->columns(2)->columnSpanFull(),
            Section::make('Eligible users')->schema([
                Select::make('department_id')->relationship('department', 'name')->helperText('Users must belong to this department.'),
                Select::make('team_id')->relationship('team', 'name')->searchable()->preload()->helperText('Optional: restrict to a team (and its sub-teams).'),
                Select::make('required_permission')->options(collect(Permissions::GROUPS['FIFO Queues'])->except(['queue.view', 'queue.override']))
                    ->placeholder('Queue default'),
            ])->columns(3)->columnSpanFull(),
            Section::make('Behaviour')->schema([
                Select::make('assignment_mode')->options(['auto' => 'Automatic FIFO', 'manual' => 'Manual (managers assign)'])->default('auto')->required(),
                TextInput::make('ack_timeout_minutes')->label('Acknowledgement timeout (min)')->numeric()->minValue(0)->placeholder('Global setting'),
                TextInput::make('escalate_after_attempts')->label('Escalate after N rejections / timeouts')->numeric()->minValue(0)->default(3),
                TextInput::make('sort_order')->numeric()->default(0),
            ])->columns(2)->columnSpanFull(),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('queue_type')->badge(),
                TextColumn::make('department.name'),
                TextColumn::make('team.name')->placeholder('Any team'),
                TextColumn::make('majorOutageType.name')->label('Major type')->placeholder('Any'),
                TextColumn::make('vertical.name')->placeholder('Any'),
                TextColumn::make('assignment_mode')->badge()->formatStateUsing(fn (string $state) => $state === 'auto' ? 'Automatic FIFO' : 'Manual'),
                TextColumn::make('ack_timeout_minutes')->label('Ack')->suffix('m')->placeholder('global'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->recordActions([EditAction::make()->modalWidth('4xl')]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageQueueDefinitions::route('/')];
    }
}
