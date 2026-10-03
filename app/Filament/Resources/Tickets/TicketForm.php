<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\QueueType;
use App\Models\Customer;
use App\Models\Fdt;
use App\Models\Olt;
use App\Models\Port;
use App\Models\Region;
use App\Models\Slot;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketSubcategory;
use App\Services\Tickets\OutageLocator;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * "Raise ticket" and "Book site visit" forms. The account number is looked up in the customer
 * database: found, the customer's name, numbers, address and position on the network are filled
 * in; not found, the agent captures them by hand and the next customer import completes the
 * record. An ongoing outage on the customer's OLT / FDT / slot / port is flagged straight away.
 */
final class TicketForm
{
    public static function ticket(Schema $schema): Schema
    {
        return $schema->components([
            self::customerSection(),
            Section::make('Issue reported')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->schema([
                    Select::make('category_id')->label('Category')->live()->dehydrated(false)
                        ->options(fn () => TicketCategory::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                        ->afterStateUpdated(fn (Set $set) => $set('subcategory_id', null)),
                    Select::make('subcategory_id')->label('Subcategory')->required()->searchable()->live()
                        ->options(fn (Get $get) => self::subcategoryOptions($get('category_id')))
                        ->helperText(fn (Get $get) => self::routing($get('subcategory_id'))),
                    Select::make('ticket_type')->label('Ticket type')->options(Ticket::TYPES)->default('reactive')->required(),
                    Select::make('source')->label('Source')->options(Ticket::SOURCES)->default('call'),
                    TextInput::make('amount')->label('Amount (refund / credit)')->numeric()->minValue(0)->maxValue(999999999)
                        ->visible(fn (Get $get) => self::subcategory($get('subcategory_id'))?->approval_desk !== null),
                    TextInput::make('external_reference')->label('Billing system ticket no.')->maxLength(60)->placeholder('TKT-148866'),
                    TextInput::make('preferred_time')->label('Customer availability')->maxLength(120)->placeholder('e.g. available today'),
                    Textarea::make('description')->label('Narration')->rows(3)->maxLength(5000)->columnSpanFull()
                        ->placeholder('What the customer said, what was checked…'),
                ])->columns(2),
        ]);
    }

    public static function visit(Schema $schema): Schema
    {
        return $schema->components([
            self::customerSection(),
            Section::make('Visit')
                ->icon('heroicon-o-map-pin')
                ->schema([
                    Select::make('visit_type_id')->label('Visit type')->required()->options(fn () => TicketActions::visitTypes())
                        ->helperText('Numbers the visit: INS-1, WiE-1, SUP-1, SHI-1…'),
                    Select::make('subcategory_id')->label('Issue reported')->searchable()->live()
                        ->options(fn () => self::subcategoryOptions(null, visitsOnly: true))
                        ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                            if (blank($get('visit_type_id')) && ($type = self::subcategory($state)?->visit_type_id)) {
                                $set('visit_type_id', $type);
                            }
                        }),
                    DateTimePicker::make('scheduled_at')->label('Date / time agreed with the customer')->seconds(false)
                        ->minDate(fn () => now()->startOfMinute())->helperText('Leave empty for “as soon as possible”.'),
                    TextInput::make('preferred_time')->label('Customer availability')->maxLength(120)->placeholder('e.g. available tomorrow morning'),
                    Select::make('ticket_type')->label('Ticket type')->options(Ticket::TYPES)->default('reactive')->required(),
                    Select::make('source')->label('Source')->options(Ticket::SOURCES)->default('call'),
                    TextInput::make('external_reference')->label('Billing system ticket no.')->maxLength(60),
                    Textarea::make('description')->label('Narration / what the technician must do')->rows(3)->maxLength(5000)->columnSpanFull(),
                ])->columns(2),
            Section::make('Dispatch now (optional)')
                ->icon('heroicon-o-truck')
                ->description('Leave empty and the visit goes to the dispatcher queue.')
                ->visible(fn () => (bool) Auth::user()?->can('visit.dispatch'))
                ->schema([
                    Select::make('technician_id')->label('Technician')->searchable()
                        ->placeholder('Dispatcher decides')
                        ->options(fn () => TicketActions::deskUsers(QueueType::VisitTechnician)),
                    Select::make('team_id')->label('Team')->options(fn () => TicketActions::teams())->placeholder('Any team'),
                ])->columns(2)->collapsed(),
        ]);
    }

    private static function customerSection(): Section
    {
        return Section::make('Customer')
            ->icon('heroicon-o-user')
            ->schema([
                TextInput::make('account_number')->label('Account number')->maxLength(60)->live(onBlur: true)->autofocus()
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        if (! ($customer = Customer::findByAccount($state))) {
                            return;
                        }

                        foreach ([
                            'customer_name' => $customer->name, 'primary_number' => $customer->primary_phone, 'alt_number_1' => $customer->alt_phone_1,
                            'alt_number_2' => $customer->alt_phone_2, 'address' => $customer->address, 'landmark' => $customer->landmark,
                            'region_id' => $customer->region_id ?? $customer->olt?->region_id, 'olt_id' => $customer->olt_id, 'fdt_id' => $customer->fdt_id,
                            'slot_id' => $customer->slot_id, 'port_id' => $customer->port_id, 'bandwidth' => $customer->bandwidth,
                        ] as $field => $value) {
                            $set($field, $value);
                        }
                    }),
                TextEntry::make('customer_notice')->label('Customer database')
                    ->state(fn (Get $get) => self::customerNotice($get('account_number')))
                    ->color(fn (Get $get) => self::customerFound($get('account_number')) ? 'success' : 'warning'),
                TextEntry::make('outage_notice')->label('Ongoing outage')->columnSpanFull()->color('danger')->weight('bold')
                    ->state(fn (Get $get) => self::outageNotice($get('olt_id'), $get('fdt_id'), $get('slot_id'), $get('port_id')))
                    ->visible(fn (Get $get) => self::outageNotice($get('olt_id'), $get('fdt_id'), $get('slot_id'), $get('port_id')) !== null),
                TextInput::make('customer_name')->label('Customer name')->maxLength(190),
                TextInput::make('calling_number')->label('Calling number')->required()->tel()->maxLength(40),
                TextInput::make('primary_number')->label('Primary number')->tel()->maxLength(40),
                TextInput::make('alt_number_1')->label('Alternative number 1')->tel()->maxLength(40),
                TextInput::make('alt_number_2')->label('Alternative number 2')->tel()->maxLength(40),
                TextInput::make('bandwidth')->label('Bandwidth / package')->maxLength(60),
                TextInput::make('address')->label('Customer address')->maxLength(250),
                TextInput::make('landmark')->label('Landmark')->maxLength(250),
                Select::make('region_id')->label('Region')->options(fn () => Region::query()->active()->orderBy('name')->pluck('name', 'id'))
                    ->helperText('Taken from the OLT when left empty.'),
                Select::make('olt_id')->label('OLT')->searchable()->live()
                    ->options(fn () => Olt::query()->active()->orderBy('name')->pluck('name', 'id'))
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $set('fdt_id', null);
                        $set('slot_id', null);
                        $set('port_id', null);

                        if ($region = self::id($state) ? Olt::query()->whereKey(self::id($state))->value('region_id') : null) {
                            $set('region_id', $region);
                        }
                    }),
                Select::make('fdt_id')->label('FDT')->searchable()->live()
                    ->options(fn (Get $get) => self::id($get('olt_id')) ? Fdt::query()->where('olt_id', self::id($get('olt_id')))->orderBy('name')->pluck('name', 'id') : [])
                    ->afterStateUpdated(function (Set $set): void {
                        $set('slot_id', null);
                        $set('port_id', null);
                    }),
                Select::make('slot_id')->label('Slot')->live()
                    ->options(fn (Get $get) => self::id($get('fdt_id')) ? Slot::query()->where('fdt_id', self::id($get('fdt_id')))->orderBy('id')->pluck('name', 'id') : [])
                    ->afterStateUpdated(fn (Set $set) => $set('port_id', null)),
                Select::make('port_id')->label('Port')->live()
                    ->options(fn (Get $get) => self::id($get('slot_id')) ? Port::query()->where('slot_id', self::id($get('slot_id')))->orderBy('id')->pluck('name', 'id') : []),
            ])->columns(['default' => 1, 'sm' => 2, 'xl' => 4]);
    }

    // ------------------------------------------------------------------ lookups

    public static function customerFound(mixed $account): bool
    {
        return is_string($account) && Customer::findByAccount($account) !== null;
    }

    private static function customerNotice(mixed $account): string
    {
        if (! is_string($account) || trim($account) === '') {
            return 'Type the account number: the customer is looked up as you leave the field.';
        }

        $customer = Customer::query()->with(['olt:id,name', 'fdt:id,name', 'slot:id,name', 'port:id,name'])->where('account_number', Customer::normaliseAccount($account))->first();

        return match (true) {
            $customer === null => 'Not in the customer database. Capture the details by hand: the record is created with this ticket and completed by the next customer import.',
            $customer->isProvisional() => 'On file from an earlier ticket (captured by hand, not yet confirmed by the customer file).'.(($path = $customer->topologyPath()) ? " {$path}" : ''),
            default => 'Found: '.collect([$customer->name, $customer->topologyPath(), $customer->bandwidth])->filter()->implode(' · '),
        };
    }

    private static function outageNotice(mixed $olt, mixed $fdt, mixed $slot, mixed $port): ?string
    {
        if (! self::id($olt)) {
            return null;
        }

        $match = app(OutageLocator::class)->ongoingFor(self::id($olt), self::id($fdt), self::id($slot), self::id($port));

        if (! $match) {
            return null;
        }

        return ($match['certain'] ? 'This customer is affected by' : 'This customer may be affected by')
            ." ongoing outage {$match['outage']->ticket_number} — {$match['outage']->title} ({$match['outage']->status?->name}, "
            .($match['confirmed'] ? 'confirmed by NOC' : 'not yet confirmed by NOC').'). Advise the customer; the ticket is linked to the outage.';
    }

    public static function subcategoryOptions(mixed $categoryId, bool $visitsOnly = false): array
    {
        return TicketSubcategory::query()->active()->with('category:id,name')
            ->when(self::id($categoryId), fn ($q, $id) => $q->where('ticket_category_id', $id))
            ->when($visitsOnly, fn ($q) => $q->where('allows_site_visit', true))
            ->orderBy('ticket_category_id')->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'ticket_category_id', 'name'])
            ->mapWithKeys(fn (TicketSubcategory $sub) => [$sub->id => self::id($categoryId) ? $sub->name : "{$sub->category?->name} — {$sub->name}"])
            ->all();
    }

    /** "Goes to CX Tier II · approved by CX Manager · carried out by Billing" */
    private static function routing(mixed $subcategoryId): ?string
    {
        if (! ($sub = self::subcategory($subcategoryId))) {
            return null;
        }

        return collect([
            'Goes to '.$sub->first_desk->getLabel(),
            $sub->approval_desk && $sub->approval_desk !== $sub->first_desk ? 'approved by '.$sub->approval_desk->getLabel() : null,
            $sub->fulfilment_desk ? 'carried out by '.$sub->fulfilment_desk->getLabel() : null,
            $sub->check_outage ? 'checked against ongoing outages' : null,
        ])->filter()->implode(' · ');
    }

    private static function subcategory(mixed $id): ?TicketSubcategory
    {
        return self::id($id) ? TicketSubcategory::query()->find(self::id($id)) : null;
    }

    /** A numeric id or null (PostgreSQL rejects anything else in an id comparison). */
    private static function id(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
