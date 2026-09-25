<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Priorities\PriorityResource;
use App\Filament\Resources\QueueDefinitions\QueueDefinitionResource;
use App\Models\Priority;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** FIFO & SLA configuration (spec §27.8) — no code changes required. */
class FifoSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'FIFO & SLA Settings';

    protected static ?string $title = 'FIFO & SLA Settings';

    protected string $view = 'filament.pages.settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('config.admin');
    }

    public function mount(): void
    {
        $settings = app(Settings::class);
        $this->form->fill(collect(Settings::DEFAULTS)->keys()
            ->mapWithKeys(fn (string $key) => [str_replace('.', '__', $key) => $settings->get($key)])
            ->all());
    }

    public function form(Schema $schema): Schema
    {
        $key = fn (string $setting) => str_replace('.', '__', $setting);

        return $schema->statePath('data')->components([
            Section::make('Assignment engine')->schema([
                Toggle::make($key('fifo.enabled'))->label('Enable FIFO'),
                Radio::make($key('fifo.assignment_mode'))->label('Assignment mode')->options([
                    'auto' => 'Automatic FIFO',
                    'manual' => 'Manual (managers assign from the queue screen)',
                ])->inline(),
                Toggle::make($key('fifo.priority_aware'))->label('Priority-aware FIFO')
                    ->helperText('Serve higher priorities first; FIFO within each priority.'),
                TextEntry::make('queue_order')->label('Queue priority order')
                    ->state(fn () => new HtmlString(e(Priority::query()->active()->orderBy('queue_rank')->pluck('name')->implode(' → ')).
                        ' &nbsp;<a class="underline" href="'.PriorityResource::getUrl().'">change</a>')),
                Toggle::make($key('fifo.critical_bypass'))->label('Critical outage bypass')
                    ->helperText('Critical tickets may be assigned even when a user is at the workload limit.'),
                Toggle::make($key('fifo.manual_override'))->label('Allow manual manager override'),
                Toggle::make($key('fifo.enforce_assignee'))->label('Only the assignee (or override holders) may work a queued step'),
            ])->columns(2),

            Section::make('Eligibility')->schema([
                Toggle::make($key('fifo.require_availability'))->label('Require "Available" status'),
                Toggle::make($key('fifo.respect_shift'))->label('Respect shifts'),
                Toggle::make($key('fifo.exclude_on_leave'))->label('Exclude users on leave'),
                Toggle::make($key('fifo.skill_based'))->label('Skill-based assignment'),
                TextInput::make($key('fifo.max_active_per_user'))->label('Maximum active tickets per user')->numeric()->minValue(0)
                    ->helperText('0 = unlimited. Users may override this individually.'),
            ])->columns(2),

            Section::make('Acknowledgement, rejection & timeout')->schema([
                TextInput::make($key('fifo.ack_timeout_minutes'))->label('Default acknowledgement timeout (minutes)')->numeric()->minValue(0)
                    ->helperText('Overridden per queue and per SLA policy. 0 = no timeout.'),
                TextInput::make($key('fifo.escalate_after_attempts'))->label('Escalate after N rejections / timeouts')->numeric()->minValue(0),
                Toggle::make($key('fifo.requeue_on_rejection'))->label('Requeue on rejection'),
                Toggle::make($key('fifo.requeue_on_timeout'))->label('Requeue on timeout'),
                Radio::make($key('fifo.requeue_on_unavailable'))->label('When an assignee becomes unavailable / leaves shift')->options([
                    'none' => 'Keep assignments',
                    'unaccepted' => 'Requeue tickets not yet accepted',
                    'all' => 'Requeue all active tickets',
                ]),
            ])->columns(2),

            Section::make('SLA')->schema([
                Toggle::make($key('sla.pause_enabled'))->label('Allow SLA pause / resume on flagged statuses'),
                TextInput::make($key('sla.default_resolution_minutes'))->label('Fallback resolution target (minutes)')->numeric()->minValue(1)
                    ->helperText('Used only when no SLA policy matches.'),
                TextInput::make($key('sla.default_pre_breach_minutes'))->label('Fallback pre-breach warning (minutes)')->numeric()->minValue(0),
                Toggle::make($key('notifications.credit_due_on_breach'))->label('Send credit-due notice on breach (fallback)'),
            ])->columns(2),

            Section::make('Queues')->schema([
                TextEntry::make('queues_link')->hiddenLabel()->state(new HtmlString(
                    'Routing, department / team restrictions, per-queue timeouts and manual mode are configured on the '.
                    '<a class="underline" href="'.QueueDefinitionResource::getUrl().'">FIFO Queues</a> screen.'
                )),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save settings')->icon(Heroicon::OutlinedCheck)->action('save'),
        ];
    }

    public function save(): void
    {
        $settings = app(Settings::class);
        $state = $this->form->getState();
        $values = [];

        foreach (Settings::DEFAULTS as $setting => $default) {
            $field = str_replace('.', '__', $setting);
            if (! array_key_exists($field, $state)) {
                continue;
            }
            $value = $state[$field];
            $values[$setting] = match (true) {
                is_bool($default) => (bool) $value,
                is_int($default) => (int) $value,
                default => $value,
            };
        }

        $settings->set($values, Auth::id());

        // New rules may make waiting tickets assignable right away.
        app(FifoAssignmentEngine::class)->process();

        Notification::make()->title('Settings saved')->success()->send();
    }
}
