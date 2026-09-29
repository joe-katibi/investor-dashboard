<?php

namespace App\Filament\Resources\Outages;

use App\Enums\TeamType;
use App\Models\Fdt;
use App\Models\MajorOutageType;
use App\Models\Olt;
use App\Models\OutageType;
use App\Models\Port;
use App\Models\Service;
use App\Models\Slot;
use App\Models\Team;
use App\Models\Vertical;
use App\Services\Outages\PriorityResolver;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Guided create / edit form (spec §7) with the cascading OLT → FDT → Slot → Port
 * multi-selects of spec §8. Children are filtered to the selected parents, inactive
 * elements are hidden for new selections, and labels show the full path.
 */
final class OutageForm
{
    /** Sentinel option value for "select every item at this level". */
    public const ALL = 'all';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Classification')
                ->icon('heroicon-o-tag')
                ->schema([
                    TextInput::make('ticket_number')->label('Ticket number')->disabled()->dehydrated(false)
                        ->placeholder('Generated automatically on save')
                        ->visibleOn('edit'),
                    TextInput::make('title')->label('Outage title')->required()->maxLength(255)->columnSpanFull(),
                    Select::make('major_outage_type_id')->label('Major outage type')->required()->live()
                        ->options(fn () => MajorOutageType::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                        ->disabledOn('edit')
                        ->afterStateUpdated(fn (Set $set) => $set('outage_type_id', null)),
                    Select::make('outage_type_id')->label('Outage type')->required()->live()
                        ->options(fn (Get $get, string $operation) => OutageType::query()->active()
                            ->when($get('major_outage_type_id'), fn ($q, $major) => $q->where('major_outage_type_id', $major))
                            // Planned maintenance is raised with "Request planned maintenance" (CRF + sign-off).
                            ->when($operation === 'create', fn ($q) => $q->reportable())
                            ->orderBy('sort_order')->get()->mapWithKeys(fn (OutageType $t) => [$t->id => "{$t->name} ({$t->prefix})"]))
                        ->disabledOn('edit'),
                    Select::make('vertical_id')->label('Vertical')->required()->live()
                        ->options(fn () => Vertical::query()->active()->orderBy('name')->pluck('name', 'id'))
                        ->afterStateUpdated(fn (Set $set) => $set('service_ids', [])),
                    DateTimePicker::make('start_time')->label('Start time')->required()->seconds(false)->default(fn () => now()->startOfMinute())->maxDate(fn () => now()->addDay()->startOfMinute()),
                ])->columns(2),

            Section::make('Affected topology')
                ->icon('heroicon-o-share')
                ->description('OLT → FDT → Slot → Port. Each level only lists children of what you selected above it. Pick "ALL" to select every item at that level.')
                ->visible(fn (Get $get) => self::usesTopology($get('major_outage_type_id')))
                ->schema(self::topologyFields())->columns(2),

            Section::make('Impact')
                ->icon('heroicon-o-users')
                ->schema([
                    TextInput::make('customers_affected')->label('Total customers affected')->numeric()->minValue(0)->maxValue(10_000_000)
                        ->required()->default(0)->live(debounce: 400),
                    TextEntry::make('calculated_priority')->label('Impact / priority (rule-driven)')
                        ->state(fn (Get $get) => app(PriorityResolver::class)->forCustomers((int) $get('customers_affected'))?->name ?? 'No matching rule')
                        ->badge()->color('warning')
                        ->hidden(fn (Get $get) => (bool) $get('priority_is_manual')),
                    Toggle::make('priority_is_manual')->label('Override calculated priority')->live()
                        ->visible(fn () => Auth::user()?->can('outage.override_priority')),
                    Select::make('priority_id')->label('Priority')->relationship('priority', 'name', fn (Builder $query) => $query->where('is_active', true)->orderBy('queue_rank'))
                        ->visible(fn (Get $get) => (bool) $get('priority_is_manual'))
                        ->required(fn (Get $get) => (bool) $get('priority_is_manual')),
                    TextInput::make('location')->label('Location / area')->maxLength(255),
                    Select::make('service_ids')->label('Affected services')->multiple()->searchable()
                        ->options(fn (Get $get) => Service::query()->active()
                            ->where(fn ($q) => $q->whereNull('vertical_id')->when($get('vertical_id'), fn ($q2, $v) => $q2->orWhere('vertical_id', $v)))
                            ->orderBy('name')->pluck('name', 'id')->union(self::keep(Service::class, $get('service_ids')))),
                    Grid::make(3)->schema([
                        TextInput::make('sites_down')->label('Sites down')->numeric()->minValue(0)->default(0),
                        TextInput::make('cells_down')->label('Cells down')->numeric()->minValue(0)->default(0),
                        TextInput::make('power_faults')->label('Power faults')->numeric()->minValue(0)->default(0),
                    ])->visible(fn (Get $get) => self::is4g($get('vertical_id')))->columnSpanFull(),
                    Textarea::make('description')->label('Detailed description')->rows(4)->maxLength(10000)->columnSpanFull(),
                ])->columns(2),

            Section::make('Routing hint (optional)')
                ->icon('heroicon-o-user-group')
                ->description('Pre-select the field team. Dispatch confirms it; technicians are assigned by FIFO or the dispatcher.')
                ->collapsed(fn (string $operation) => $operation === 'create')
                ->schema([
                    Select::make('team_type')->label('Team type')->options(TeamType::class)->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('team_id', null);
                            $set('sub_team_id', null);
                        }),
                    Select::make('team_id')->label('Assigned team')->live()
                        ->options(fn (Get $get) => OutageActions::teamOptions($get('team_type')))
                        ->afterStateUpdated(fn (Set $set) => $set('sub_team_id', null)),
                    Select::make('sub_team_id')->label('Assigned sub-team')
                        ->options(fn (Get $get) => $get('team_id') ? Team::query()->active()->where('parent_id', $get('team_id'))->pluck('name', 'id') : []),
                ])->columns(3),

            Section::make('Customer communication')
                ->icon('heroicon-o-device-phone-mobile')
                ->visible(fn () => Auth::user()?->can('outage.update_credit_sms'))
                ->schema([
                    Toggle::make('sms_sent')->label('SMS sent'),
                    Toggle::make('credit_shared')->label('Credit shared'),
                ])->columns(2),
        ]);
    }

    /** Cascading OLT → FDT → Slot → Port selects with "ALL" (also used by the planned maintenance request). */
    public static function topologyFields(): array
    {
        return [
            self::topologySelect('olt_ids', 'OLT(s)', 'ALL OLTs', fn (Get $get) => self::oltOptions(self::ids($get('olt_ids'))))
                ->afterStateUpdated(fn (Get $get, Set $set) => self::pruneChildren($get, $set, 'olt')),
            self::topologySelect('fdt_ids', 'FDT(s)', 'ALL FDTs of the selected OLT(s)', fn (Get $get) => self::fdtOptions(self::ids($get('olt_ids')), self::ids($get('fdt_ids'))))
                ->disabled(fn (Get $get) => self::ids($get('olt_ids')) === [])
                ->afterStateUpdated(fn (Get $get, Set $set) => self::pruneChildren($get, $set, 'fdt')),
            self::topologySelect('slot_ids', 'Slot(s)', 'ALL slots of the selected FDT(s)', fn (Get $get) => self::slotOptions(self::ids($get('fdt_ids')), self::ids($get('slot_ids'))))
                ->disabled(fn (Get $get) => self::ids($get('fdt_ids')) === [])
                ->afterStateUpdated(fn (Get $get, Set $set) => self::pruneChildren($get, $set, 'slot')),
            self::topologySelect('port_ids', 'Port(s)', 'ALL ports of the selected slot(s)', fn (Get $get) => self::portOptions(self::ids($get('slot_ids')), self::ids($get('port_ids'))))
                ->disabled(fn (Get $get) => self::ids($get('slot_ids')) === []),
        ];
    }

    public static function usesTopology(mixed $majorTypeId): bool
    {
        return $majorTypeId && (bool) MajorOutageType::query()->whereKey($majorTypeId)->value('uses_topology');
    }

    public static function is4g(mixed $verticalId): bool
    {
        return $verticalId && Vertical::query()->whereKey($verticalId)->value('code') === Vertical::MOBILITY_4G;
    }

    /** Keep already-attached (possibly now inactive) items visible on edit. */
    private static function keep(string $model, mixed $ids): array
    {
        $ids = array_filter((array) $ids);

        return $ids ? $model::query()->whereIn('id', $ids)->pluck('name', 'id')->all() : [];
    }

    /**
     * Cascading topology multi-select with an "ALL" entry on top. Choosing it replaces
     * the selection with every option at that level (the sentinel is never saved).
     *
     * @param  Closure(Get): array<int, string>  $options
     */
    private static function topologySelect(string $field, string $label, string $allLabel, Closure $options): Select
    {
        return Select::make($field)->label($label)->multiple()->searchable()->live()
            ->options(function (Get $get) use ($field, $allLabel, $options): array {
                $list = $options($get);
                $unselected = array_diff(array_keys($list), self::ids($get($field)));

                return count($list) > 1 && $unselected !== []
                    ? [self::ALL => $allLabel.' ('.count($list).')'] + $list
                    : $list;
            })
            ->afterStateUpdated(function (Get $get, Set $set) use ($field, $options): void {
                if (in_array(self::ALL, (array) $get($field), true)) {
                    $set($field, array_keys($options($get)));
                }
            })
            ->dehydrateStateUsing(fn (Get $get, mixed $state) => in_array(self::ALL, (array) $state, true)
                ? array_keys($options($get))
                : self::ids($state));
    }

    /** Selected topology ids without the "ALL" sentinel. */
    public static function ids(mixed $state): array
    {
        return array_values(array_map('intval', array_filter((array) $state, fn ($id) => is_int($id) || ctype_digit((string) $id))));
    }

    // The option helpers clean their own inputs: form state can hold the "ALL" sentinel, and
    // PostgreSQL rejects non-numeric values in an id comparison (MySQL / SQLite silently cast them).

    public static function oltOptions(array $selected = []): array
    {
        $selected = self::ids($selected);

        return Olt::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $selected ?: [0]))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function fdtOptions(array $oltIds, array $selected = []): array
    {
        [$oltIds, $selected] = [self::ids($oltIds), self::ids($selected)];

        if ($oltIds === []) {
            return [];
        }

        return Fdt::query()->with('olt:id,name')->whereIn('olt_id', $oltIds)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $selected ?: [0]))
            ->orderBy('olt_id')->orderBy('name')->get()
            ->mapWithKeys(fn (Fdt $fdt) => [$fdt->id => $fdt->path])->all();
    }

    public static function slotOptions(array $fdtIds, array $selected = []): array
    {
        [$fdtIds, $selected] = [self::ids($fdtIds), self::ids($selected)];

        if ($fdtIds === []) {
            return [];
        }

        return Slot::query()->with('fdt.olt:id,name')->whereIn('fdt_id', $fdtIds)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $selected ?: [0]))
            ->orderBy('fdt_id')->orderBy('name')->get()
            ->mapWithKeys(fn (Slot $slot) => [$slot->id => $slot->path])->all();
    }

    public static function portOptions(array $slotIds, array $selected = []): array
    {
        [$slotIds, $selected] = [self::ids($slotIds), self::ids($selected)];

        if ($slotIds === []) {
            return [];
        }

        return Port::query()->with('slot.fdt.olt:id,name')->whereIn('slot_id', $slotIds)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $selected ?: [0]))
            ->orderBy('slot_id')->orderBy('name')->get()
            ->mapWithKeys(fn (Port $port) => [$port->id => $port->path])->all();
    }

    /** After a parent changes, drop selected children that no longer belong to a selected parent. */
    private static function pruneChildren(Get $get, Set $set, string $level): void
    {
        if ($level === 'olt') {
            $set('fdt_ids', array_values(array_intersect(self::ids($get('fdt_ids')), array_keys(self::fdtOptions(self::ids($get('olt_ids')), self::ids($get('fdt_ids')))))));
        }

        if (in_array($level, ['olt', 'fdt'], true)) {
            $set('slot_ids', array_values(array_intersect(self::ids($get('slot_ids')), array_keys(self::slotOptions(self::ids($get('fdt_ids')), self::ids($get('slot_ids')))))));
        }

        $set('port_ids', array_values(array_intersect(self::ids($get('port_ids')), array_keys(self::portOptions(self::ids($get('slot_ids')), self::ids($get('port_ids')))))));
    }
}
