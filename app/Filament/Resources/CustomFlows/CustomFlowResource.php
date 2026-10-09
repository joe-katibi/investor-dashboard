<?php

namespace App\Filament\Resources\CustomFlows;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\CustomFlows\Pages\CreateCustomFlow;
use App\Filament\Resources\CustomFlows\Pages\EditCustomFlow;
use App\Filament\Resources\CustomFlows\Pages\ListCustomFlows;
use App\Models\CustomFlow;
use App\Models\Team;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * Workflow configuration, stage 2: flows built from the screen. A flow is a name, a ticket prefix
 * and an ordered list of steps — add, remove and drag them into order. Tickets already raised keep
 * the steps they started with, so a flow can be changed at any time.
 */
class CustomFlowResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'workflow.configure';

    protected static ?string $model = CustomFlow::class;

    protected static ?string $modelLabel = 'custom workflow';

    protected static ?string $navigationLabel = 'Custom Workflows';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'custom-workflows';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The workflow')->schema([
                TextInput::make('name')->required()->maxLength(120)->placeholder('e.g. Purchase request'),
                TextInput::make('prefix')->label('Ticket prefix')->required()->alpha()->maxLength(8)->unique(ignoreRecord: true)
                    ->placeholder('e.g. PUR')->helperText('Ticket numbers start with it: PUR-000001.')
                    ->disabled(fn (?CustomFlow $record) => (bool) $record?->tickets()->exists())
                    ->dehydrated(fn (?CustomFlow $record) => ! $record?->tickets()->exists()),
                Textarea::make('description')->rows(2)->maxLength(2000)->columnSpanFull()
                    ->helperText('Shown to people when they choose the workflow to raise a ticket in.'),
                TextInput::make('sla_minutes')->label('SLA of the whole ticket (minutes)')->numeric()->minValue(1)->maxValue(1000000)
                    ->placeholder('Fallback of FIFO & SLA Settings')->helperText('From raised to completed, every minute counting.'),
                Select::make('raise_roles')->label('Who may raise a ticket')->multiple()->searchable()
                    ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                    ->placeholder('Everyone who may raise custom tickets')
                    ->helperText('People also need the permission “Raise tickets in custom workflows”.'),
                Toggle::make('requires_resolution')->label('A closing note is required at the last step')->default(true),
                Toggle::make('is_active')->label('Active — tickets can be raised')->default(true)
                    ->helperText('Switched off: no new tickets; the ones on their way carry on.'),
            ])->columns(2),

            Section::make('Steps')
                ->description('In the order a ticket goes through them — drag to reorder, add or remove at will. Each step is a queue of its own for the team named on it: '
                    .'the oldest ticket goes to the team member waiting longest. Tickets already raised keep the steps they started with.')
                ->schema([
                    Repeater::make('steps')->hiddenLabel()->relationship('steps')->orderColumn('position')->reorderable()
                        ->minItems(1)->defaultItems(1)->addActionLabel('Add a step')->collapsible()
                        ->itemLabel(fn (array $state) => filled($state['name'] ?? null) ? $state['name'] : 'New step')
                        ->schema([
                            TextInput::make('name')->label('Step')->required()->maxLength(120)->placeholder('e.g. Finance approval'),
                            Select::make('team_id')->label('Worked by (team)')->required()->searchable()->preload()
                                ->options(fn () => Team::query()->active()->orderBy('name')->pluck('name', 'id'))
                                ->helperText('The members of this team (and its sub-teams) receive the tickets.'),
                            Select::make('assignment_mode')->label('How tickets are given out')->required()->default('auto')->selectablePlaceholder(false)
                                ->options(['auto' => 'Automatic FIFO', 'manual' => 'Manual — a manager assigns']),
                            TextInput::make('target_minutes')->label('Step SLA target (minutes)')->numeric()->minValue(0)->maxValue(100000)
                                ->placeholder('None')->helperText('How long the ticket may stay at this step; warned before and told at the breach.'),
                            Toggle::make('can_send_back')->label('May send the ticket back to the step before')->default(true),
                            Toggle::make('note_required')->label('A note is required to complete the step'),
                            Textarea::make('instructions')->label('What is to be done at this step')->rows(2)->maxLength(2000)->columnSpanFull(),
                        ])->columns(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('steps.team:id,name')->withCount([
                'tickets as open_tickets_count' => fn (Builder $tickets) => $tickets->whereHas('outage', fn (Builder $outage) => $outage->where('is_closed', false)),
                'tickets',
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold')
                    ->description(fn (CustomFlow $record) => $record->steps->pluck('name')->implode(' → ') ?: 'No steps yet'),
                TextColumn::make('prefix')->badge()->color('gray'),
                TextColumn::make('version')->label('Version')->prefix('v'),
                TextColumn::make('open_tickets_count')->label('Open tickets')->badge()->color(fn (int $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('tickets_count')->label('Tickets so far'),
                TextColumn::make('ready')->label('Ready?')->state(fn (CustomFlow $record) => $record->problems() === [] ? 'Ready' : implode(' ', $record->problems()))
                    ->badge()->color(fn (string $state) => $state === 'Ready' ? 'success' : 'danger')->wrap(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomFlows::route('/'),
            'create' => CreateCustomFlow::route('/create'),
            'edit' => EditCustomFlow::route('/{record}/edit'),
        ];
    }
}
