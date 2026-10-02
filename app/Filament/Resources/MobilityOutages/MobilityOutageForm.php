<?php

namespace App\Filament\Resources\MobilityOutages;

use App\Models\Enodeb;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\Service;
use App\Models\TransmissionNode;
use App\Models\Vendor;
use App\Models\Vertical;
use App\Services\Mobility\MobilityImpact;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * "Report 4G outage" / edit form. The outage type decides the rest: internal types (eNodeB,
 * Transmission) can be assigned to an engineer straight away, vendor types (Power) need the
 * responsible vendor, who is e-mailed as soon as the ticket is saved. Severity is worked out
 * live from the sites and transmission nodes selected.
 */
final class MobilityOutageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Outage')
                ->icon('heroicon-o-signal')
                ->schema([
                    TextInput::make('ticket_number')->label('Ticket number')->disabled()->dehydrated(false)->visibleOn('edit'),
                    Select::make('outage_type_id')->label('4G outage type')->required()->live()
                        ->options(fn () => OutageType::query()->mobility()->active()->orderBy('sort_order')->get()
                            ->mapWithKeys(fn (OutageType $type) => [$type->id => "{$type->name} ({$type->prefix})"]))
                        ->helperText(fn (Get $get) => self::type($get('outage_type_id'))?->description)
                        ->disabledOn('edit')
                        ->afterStateUpdated(function (Set $set): void {
                            $set('vendor_id', null);
                            $set('engineer_id', null);
                        }),
                    TextInput::make('title')->label('Outage title')->required()->maxLength(255)
                        ->placeholder('e.g. Nsambya Kevina — site down on mains failure'),
                    DateTimePicker::make('start_time')->label('Outage start time')->required()->seconds(false)
                        ->default(fn () => now()->startOfMinute())->maxDate(fn () => now()->addMinutes(5)->startOfMinute()),
                    DateTimePicker::make('detected_at')->label('Detection time')->required()->seconds(false)
                        ->default(fn () => now()->startOfMinute())->maxDate(fn () => now()->addMinutes(5)->startOfMinute())
                        ->helperText('When the NOC saw the alarm or was told.'),
                ])->columns(2),

            Section::make('Affected network')
                ->icon('heroicon-o-share')
                ->description('Pick the eNodeB sites and / or transmission nodes that are down. A transmission node takes everything below it with it (RSG → ASG → CSG → SSG → eNodeB).')
                ->schema([
                    Select::make('enodeb_ids')->label('eNodeB site(s)')->multiple()->searchable()->live()
                        ->options(fn (Get $get) => self::siteOptions((array) $get('enodeb_ids')))
                        ->afterStateUpdated(function (Get $get, Set $set, ?array $state): void {
                            // Power ticket: default to the tower company of the first site.
                            if (blank($get('vendor_id')) && self::type($get('outage_type_id'))?->usesVendor() && ($first = self::ids($state)[0] ?? null)) {
                                $vendor = Enodeb::query()->whereKey($first)->value('vendor_id');
                                $set('vendor_id', $vendor && Vendor::query()->active()->whereKey($vendor)->exists() ? $vendor : null);
                            }
                        }),
                    Select::make('node_ids')->label('Transmission node(s)')->multiple()->searchable()->live()
                        ->options(fn (Get $get) => self::nodeOptions((array) $get('node_ids'))),
                    TextInput::make('cells_down')->label('Cells down')->numeric()->minValue(0)->maxValue(100000)->default(0)->live(debounce: 400),
                    TextInput::make('customers_affected')->label('Customers affected (if known)')->numeric()->minValue(0)->maxValue(10_000_000)->default(0),
                    TextInput::make('transmission_link_id')->label('Transmission link ID')->maxLength(120)
                        ->visible(fn (Get $get) => self::type($get('outage_type_id'))?->fault_domain === OutageType::DOMAIN_TRANSMISSION),
                    Select::make('transmission_type')->label('Transmission type')->options(Outage::TRANSMISSION_TYPES)
                        ->visible(fn (Get $get) => self::type($get('outage_type_id'))?->fault_domain === OutageType::DOMAIN_TRANSMISSION),
                    TextInput::make('location')->label('Location / area')->maxLength(255),
                    Select::make('service_ids')->label('Affected services')->multiple()->searchable()
                        ->options(fn (Get $get) => self::serviceOptions((array) $get('service_ids'))),
                ])->columns(2),

            Section::make('Severity')
                ->icon('heroicon-o-adjustments-vertical')
                ->schema([
                    TextEntry::make('calculated_impact')->label('Affected')
                        ->state(fn (Get $get) => MobilityImpact::describe(self::counts($get)) ?: 'Nothing selected yet'),
                    TextEntry::make('calculated_severity')->label('Severity (4G impact rules)')
                        ->state(fn (Get $get) => self::severity($get))
                        ->badge()->color('warning')
                        ->hidden(fn (Get $get) => (bool) $get('priority_is_manual')),
                    Toggle::make('priority_is_manual')->label('Set the severity manually')->live(),
                    Select::make('priority_id')->label('Severity')
                        ->options(fn () => self::priorityOptions())
                        ->visible(fn (Get $get) => (bool) $get('priority_is_manual'))
                        ->required(fn (Get $get) => (bool) $get('priority_is_manual')),
                ])->columns(2),

            Section::make('Responsible')
                ->icon('heroicon-o-user-group')
                ->visibleOn('create')
                ->schema([
                    Select::make('vendor_id')->label('Vendor')->searchable()
                        ->options(fn () => Vendor::query()->active()->orderBy('name')->get()
                            ->mapWithKeys(fn (Vendor $vendor) => [$vendor->id => $vendor->name.' — '.$vendor->typeLabel().($vendor->email ? '' : ' (no e-mail address!)')]))
                        ->visible(fn (Get $get) => (bool) self::type($get('outage_type_id'))?->usesVendor())
                        ->required(fn (Get $get) => (bool) self::type($get('outage_type_id'))?->usesVendor())
                        ->helperText('The vendor is e-mailed automatically when the ticket is created. Vendors do not use this system: record their replies with “Add vendor update”.'),
                    Select::make('engineer_id')->label('Assign to engineer / technician')->searchable()
                        ->placeholder('Assign later')
                        ->options(fn () => MobilityActions::engineerOptions())
                        ->visible(fn (Get $get) => filled($get('outage_type_id')) && ! self::type($get('outage_type_id'))?->usesVendor()),
                    TextInput::make('assignment_note')->label('Instructions for the engineer')->maxLength(255)
                        ->visible(fn (Get $get) => filled($get('engineer_id'))),
                ])->columns(2),

            Section::make('Details')
                ->icon('heroicon-o-document-text')
                ->schema([
                    Textarea::make('description')->label('Description')->rows(4)->maxLength(10000),
                    Textarea::make('service_impact')->label('Service impact')->rows(4)->maxLength(5000)
                        ->placeholder('e.g. No 4G coverage around the site; neighbouring sites carrying the load.'),
                ])->columns(2),
        ]);
    }

    // ------------------------------------------------------------------ options

    /** Active sites, plus already-selected (possibly inactive) ones. */
    public static function siteOptions(array $selected = []): array
    {
        $selected = self::ids($selected);

        return Enodeb::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $selected ?: [0]))
            ->orderBy('name')->get(['id', 'site_id', 'name', 'tower_company'])
            ->mapWithKeys(fn (Enodeb $site) => [$site->id => $site->label()])->all();
    }

    /** Nodes in service, top of the hierarchy first. */
    public static function nodeOptions(array $selected = []): array
    {
        $selected = self::ids($selected);
        $order = array_flip(array_keys(TransmissionNode::TYPES));

        return TransmissionNode::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $selected ?: [0]))
            ->orderBy('code')->get(['id', 'type', 'code', 'name'])
            ->sortBy(fn (TransmissionNode $node) => [$order[$node->type] ?? 9, $node->code])
            ->mapWithKeys(fn (TransmissionNode $node) => [$node->id => $node->label()])->all();
    }

    public static function serviceOptions(array $selected = []): array
    {
        $vertical = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');

        return Service::query()
            ->where(fn ($q) => $q->where(fn ($active) => $active->where('is_active', true)
                ->where(fn ($v) => $v->whereNull('vertical_id')->orWhere('vertical_id', $vertical)))->orWhereIn('id', self::ids($selected) ?: [0]))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function priorityOptions(): array
    {
        $vertical = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');

        return Priority::query()->active()->where('vertical_id', $vertical)->orderBy('queue_rank')->pluck('name', 'id')->all();
    }

    // ------------------------------------------------------------------ live severity

    private static function counts(Get $get): array
    {
        return app(MobilityImpact::class)->counts(self::ids($get('enodeb_ids')), self::ids($get('node_ids')), (int) $get('cells_down'), (int) $get('customers_affected'));
    }

    private static function severity(Get $get): string
    {
        $type = self::type($get('outage_type_id'));

        if (! $type) {
            return 'Select the outage type';
        }

        $vertical = $type->vertical_id ?? Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');
        $result = app(MobilityImpact::class)->severity((int) $vertical, $type->id, self::counts($get));

        return ($result['priority']?->name ?? 'No matching rule').($result['rule'] ? ' — '.$result['rule']->name : '');
    }

    private static function type(mixed $id): ?OutageType
    {
        return is_int($id) || ctype_digit((string) $id) ? OutageType::query()->mobility()->find((int) $id) : null;
    }

    /** Numeric ids only (PostgreSQL rejects anything else in an id comparison). */
    public static function ids(mixed $state): array
    {
        return array_values(array_map('intval', array_filter((array) $state, fn ($id) => is_int($id) || ctype_digit((string) $id))));
    }
}
