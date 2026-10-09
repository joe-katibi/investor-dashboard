<?php

namespace App\Filament\Pages;

use App\Enums\QueueType;
use App\Filament\Resources\CustomFlows\CustomFlowResource;
use App\Filament\Resources\DeskRoutes\DeskRouteResource;
use App\Filament\Resources\NotificationRules\NotificationRuleResource;
use App\Filament\Resources\QueueDefinitions\QueueDefinitionResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Services\ServiceResource;
use App\Filament\Resources\TicketSubcategories\TicketSubcategoryResource;
use App\Models\CustomFlow;
use App\Models\Department;
use App\Models\Team;
use App\Services\Workflows\WorkflowConfig;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Workflow configuration (stage 1): each flow step by step — who works a step, how it is given
 * out, how long they have, the desk's SLA target — and the switches that add or drop a step.
 * Everything is changed here without code; tickets already past a step keep what was in force.
 */
class WorkflowConfiguration extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Workflow Configuration';

    protected static ?string $title = 'Workflow Configuration';

    protected static ?string $slug = 'workflow-configuration';

    protected string $view = 'filament.pages.workflow-configuration';

    #[Url]
    public string $flow = 'outage';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('workflow.configure');
    }

    public function getSubheading(): ?string
    {
        return 'How each kind of ticket moves through the organisation. Changes apply to tickets reaching a step from now on.';
    }

    public function updatedFlow(): void
    {
        $this->flow = $this->selected();
    }

    private function selected(): string
    {
        return app(WorkflowConfig::class)->has($this->flow) ? $this->flow : 'outage';
    }

    protected function getViewData(): array
    {
        $config = app(WorkflowConfig::class);
        $flow = $this->selected();

        return [
            'flow' => $flow,
            'flows' => $config->flows(),
            'steps' => $config->steps($flow),
            'switches' => $config->switches($flow),
            'history' => $config->history(),
            'links' => $this->links($flow),
            // Stage 2: the workflows built from scratch, with their steps.
            'customFlows' => CustomFlow::query()->with('steps')->orderBy('name')->get(),
            'customUrl' => CustomFlowResource::canAccess() ? CustomFlowResource::getUrl() : null,
        ];
    }

    /** Who works a step, how it is handed out and how long they have. */
    public function editStepAction(): Action
    {
        $desk = fn (array $arguments): QueueType => QueueType::from((string) ($arguments['desk'] ?? ''));

        return Action::make('editStep')
            ->label('Change')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->link()
            ->modalHeading(fn (array $arguments) => 'Step: '.$desk($arguments)->getLabel())
            ->modalDescription('Applies to tickets that reach this step from now on. Who may work the step is decided by the roles (permission shown on the page); here you narrow it and set the times.')
            ->modalSubmitActionLabel('Save step')
            ->fillForm(fn (array $arguments) => app(WorkflowConfig::class)->stepForm($desk($arguments)))
            ->schema([
                Radio::make('assignment_mode')->label('How tickets are given out')->required()->options([
                    'auto' => 'Automatic FIFO — the oldest ticket goes to the person waiting longest',
                    'manual' => 'Manual — a manager assigns each ticket from the FIFO Queues screen',
                ]),
                Select::make('department_id')->label('Only people of this department')->placeholder('Any department')
                    ->options(fn () => Department::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                Select::make('team_id')->label('Only people of this team (and its sub-teams)')->placeholder('Any team')
                    ->options(fn () => Team::query()->active()->orderBy('name')->pluck('name', 'id'))->searchable(),
                TextInput::make('ack_timeout_minutes')->label('Time to accept (minutes)')->numeric()->minValue(0)->maxValue(10080)
                    ->placeholder('Global setting')->helperText('Not accepted in time: the ticket goes to the next person. 0 = no limit; empty = the global setting.'),
                TextInput::make('escalate_after_attempts')->label('Escalate after this many rejections / time-outs')->numeric()->minValue(0)->maxValue(50)->required(),
                TextInput::make('target_minutes')->label('Desk SLA target (minutes)')->numeric()->minValue(0)->maxValue(100000)->required()
                    ->helperText('How long a ticket may wait at this step before the step acts on it. 0 = no target.'),
            ])
            ->action(function (array $data, array $arguments) use ($desk): void {
                abort_unless(static::canAccess(), 403);

                try {
                    app(WorkflowConfig::class)->updateStep($desk($arguments), $data, Auth::user());
                } catch (ValidationException $exception) {
                    Notification::make()->title('Not saved')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();

                    return;
                }

                Notification::make()->title('Step saved: '.$desk($arguments)->getLabel())->success()->send();
            });
    }

    /** The switches of the flow on screen. */
    public function editSwitchesAction(): Action
    {
        $field = fn (string $key) => 's'.md5($key);

        return Action::make('editSwitches')
            ->label('Change switches')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->modalHeading(fn () => 'Switches: '.app(WorkflowConfig::class)->flows()[$this->selected()]['label'])
            ->modalDescription('Applies to tickets raised or moved from now on.')
            ->modalSubmitActionLabel('Save switches')
            ->fillForm(fn () => collect(app(WorkflowConfig::class)->switches($this->selected()))->mapWithKeys(fn (array $switch) => [$field($switch['key']) => $switch['value']])->all())
            ->schema(fn () => collect(app(WorkflowConfig::class)->switches($this->selected()))->map(fn (array $switch) => $switch['type'] === 'bool'
                ? Toggle::make($field($switch['key']))->label($switch['label'])->helperText($switch['help'])
                : TextInput::make($field($switch['key']))->label($switch['label'])->helperText($switch['help'])->numeric()->minValue(0)->maxValue(1000000)->required())->all())
            ->action(function (array $data) use ($field): void {
                abort_unless(static::canAccess(), 403);

                $config = app(WorkflowConfig::class);
                $values = collect($config->switches($this->selected()))
                    ->filter(fn (array $switch) => array_key_exists($field($switch['key']), $data))
                    ->mapWithKeys(fn (array $switch) => [$switch['key'] => $data[$field($switch['key'])]])->all();

                $config->saveSwitches($this->selected(), $values, Auth::user());
                Notification::make()->title('Switches saved')->success()->send();
            });
    }

    /**
     * Screens that hold the rest of a flow's configuration, for people who may open them.
     *
     * @return array<string, string> label => URL
     */
    private function links(string $flow): array
    {
        $links = [
            'Roles & permissions (who works a step)' => RoleResource::class,
            'Notification rules (who is told)' => NotificationRuleResource::class,
            'FIFO queues (queues per major type / vertical)' => QueueDefinitionResource::class,
        ];

        if ($flow === 'system') {
            $links = ['Affected services (which team reviews which system)' => ServiceResource::class] + $links;
        }

        if (in_array($flow, ['cx_ticket', 'site_visit'], true)) {
            $links = [
                'Desk routes (which desk may hand over to which)' => DeskRouteResource::class,
                'Issues (first desk, approval desk and SLA per subcategory)' => TicketSubcategoryResource::class,
            ] + $links;
        }

        return collect($links)->filter(fn (string $resource) => $resource::canAccess())->map(fn (string $resource) => $resource::getUrl())->all();
    }
}
