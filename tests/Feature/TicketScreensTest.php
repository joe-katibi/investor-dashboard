<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FifoQueue;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\Reports;
use App\Filament\Pages\TicketDashboard;
use App\Filament\Pages\TicketSettings;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ManageCustomers;
use App\Filament\Resources\DelayReasons\DelayReasonResource;
use App\Filament\Resources\DeskRoutes\DeskRouteResource;
use App\Filament\Resources\FinalReasons\FinalReasonResource;
use App\Filament\Resources\Outages\OutageResource;
use App\Filament\Resources\OutageTypes\OutageTypeResource;
use App\Filament\Resources\Regions\RegionResource;
use App\Filament\Resources\SiteVisits\Pages\CreateSiteVisit;
use App\Filament\Resources\SiteVisits\Pages\ViewSiteVisit;
use App\Filament\Resources\SiteVisits\SiteVisitResource;
use App\Filament\Resources\TicketCategories\TicketCategoryResource;
use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Resources\TicketSubcategories\TicketSubcategoryResource;
use App\Filament\Resources\VisitTypes\VisitTypeResource;
use App\Filament\Support\OutageLink;
use App\Models\Customer;
use App\Models\Fdt;
use App\Models\FinalReason;
use App\Models\Outage;
use App\Models\Region;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use App\Services\Tickets\BusinessClock;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** Customer Care screens: pages, the raise / book forms with the customer look-up, ticket actions by role, access separation, settings. */
class TicketScreensTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private User $tier2;

    private User $dispatcher;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
        $this->tier2 = $this->makeUser('CX Tier II', 'CX');
        $this->dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $this->technician = $this->makeUser('Site Visit Technician', 'DISPATCH');
    }

    private function sub(string $name): int
    {
        return TicketSubcategory::query()->where('name', $name)->value('id');
    }

    private function supportType(): int
    {
        return VisitType::query()->where('code_prefix', 'SUP')->value('id');
    }

    private function ticket(string $issue = 'Slow Speeds', array $overrides = []): Outage
    {
        Auth::setUser($this->tier2);

        return $this->workflow->createTicket(array_merge([
            'subcategory_id' => $this->sub($issue), 'account_number' => 'SF100', 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001',
        ], $overrides), $this->tier2);
    }

    private function visit(array $overrides = []): Outage
    {
        Auth::setUser($this->tier2);

        return $this->workflow->createSiteVisit(array_merge([
            'visit_type_id' => $this->supportType(), 'subcategory_id' => $this->sub('LOS Flashing Red'), 'account_number' => 'SF200',
            'customer_name' => 'John Customer', 'calling_number' => '0772000010',
        ], $overrides), $this->tier2);
    }

    public function test_every_customer_care_page_renders(): void
    {
        $ticket = $this->ticket();
        $visit = $this->workflow->assignVisit($this->visit(), $this->dispatcher, ['technician_id' => $this->technician->id]);
        $this->workflow->visitArrive($visit, $this->technician);

        $this->actingAs($this->makeUser('Ticketing Manager', 'CX'));

        foreach ([
            TicketDashboard::getUrl(), TicketSettings::getUrl(), MyWork::getUrl(), FifoQueue::getUrl(), Reports::getUrl(),
            TicketResource::getUrl('index'), TicketResource::getUrl('create'), TicketResource::getUrl('view', ['record' => $ticket]),
            SiteVisitResource::getUrl('index'), SiteVisitResource::getUrl('create'), SiteVisitResource::getUrl('view', ['record' => $visit]),
            CustomerResource::getUrl(), RegionResource::getUrl(), TicketCategoryResource::getUrl(), TicketSubcategoryResource::getUrl(),
            VisitTypeResource::getUrl(), FinalReasonResource::getUrl(), DelayReasonResource::getUrl(), DeskRouteResource::getUrl(),
            FifoQueue::getUrl(['queue' => 'visit_technician']),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(TicketDashboard::getUrl())->assertSee('SLA per department')->assertSee('Site Visit Technician')->assertSee($visit->ticket_number)->assertSee('Ticket Audit Trail');
        $this->get(TicketResource::getUrl('view', ['record' => $ticket]))->assertSee('Jane Customer')->assertSee('SF100')->assertSee('CX Tier II');
        $this->get(SiteVisitResource::getUrl('view', ['record' => $visit]))->assertSee('Arrived at premise')->assertSee($this->technician->name);
        $this->get(AuditLogResource::getUrl('index', ['filters' => ['scope' => ['value' => 'ticketing']]]))->assertOk()->assertSee('Ticket: Arrived')->assertSee($visit->ticket_number);

        // Each ticket opens on its own kind of page.
        $this->assertSame(TicketResource::getUrl('view', ['record' => $ticket->id]), OutageLink::url($ticket));
        $this->assertSame(SiteVisitResource::getUrl('view', ['record' => $visit->id]), OutageLink::url($visit));

        // The configuration screens that now also list the ticket types / statuses still open.
        $this->actingAs($this->makeUser('Super Admin', 'MGMT'));
        $this->get(OutageTypeResource::getUrl())->assertOk();
    }

    public function test_raising_a_ticket_looks_the_customer_up_and_flags_an_ongoing_outage(): void
    {
        $region = Region::query()->create(['name' => 'Central', 'is_active' => true]);
        $olt = $this->makeOlt('Kabowa');
        $olt->update(['region_id' => $region->id]);
        $fdt = Fdt::query()->create(['olt_id' => $olt->id, 'name' => 'FDT 6', 'is_active' => true]);
        Customer::query()->create([
            'account_number' => 'SF2577916', 'name' => 'Jane On File', 'primary_phone' => '0700111222', 'address' => 'Seguku', 'olt_id' => $olt->id,
            'fdt_id' => $fdt->id, 'source' => Customer::SOURCE_IMPORT, 'verified_at' => now(),
        ]);
        $network = $this->reportOutage($this->makeUser('NOC Tier I', 'NOC'), ['olt_ids' => [$olt->id], 'fdt_ids' => [$fdt->id]]);

        $this->actingAs($this->makeUser('CX Frontline', 'CX'));

        Livewire::test(CreateTicket::class)
            ->assertSee('Type the account number')
            ->set('data.account_number', 'sf2577916')
            ->assertFormSet(['customer_name' => 'Jane On File', 'primary_number' => '0700111222', 'address' => 'Seguku', 'olt_id' => $olt->id, 'fdt_id' => $fdt->id, 'region_id' => $region->id])
            ->assertSee('Found: Jane On File')
            ->assertSee($network->ticket_number)
            ->assertSee('not yet confirmed by NOC')
            ->fillForm(['subcategory_id' => $this->sub('LOS Flashing Red'), 'source' => null])
            ->call('create')
            // Source, billing system ticket number, customer availability and narration are required, with the number called from.
            ->assertHasFormErrors(['calling_number', 'source', 'external_reference', 'preferred_time', 'description'])
            ->fillForm(['calling_number' => '0772123456', 'source' => 'call', 'external_reference' => 'TKT-148866', 'preferred_time' => 'Available after 2 pm', 'description' => 'Red light since morning'])
            ->call('create')
            ->assertHasNoFormErrors();

        $outage = Outage::query()->cxTickets()->latest('id')->firstOrFail();
        $ticket = Ticket::query()->where('outage_id', $outage->id)->firstOrFail();
        $this->assertSame('SF2577916', $ticket->account_number);
        $this->assertSame($olt->id, $ticket->olt_id);
        $this->assertSame($network->id, $ticket->linked_outage_id);
        $this->assertSame(['call', 'TKT-148866', 'Available after 2 pm', 'Red light since morning'], [$ticket->source, $ticket->external_reference, $ticket->preferred_time, $outage->description]);
        $this->assertSame(QueueType::CxTier2, $outage->current_queue);

        // An account that is not on file: said so, captured by hand.
        Livewire::test(CreateTicket::class)
            ->set('data.account_number', 'SF7777777')
            ->assertSee('Not in the customer database')
            ->fillForm(['subcategory_id' => $this->sub('Wrong bill / double charge'), 'customer_name' => 'Walk In', 'calling_number' => '0772999888',
                'external_reference' => 'TKT-148867', 'preferred_time' => 'Any time', 'description' => 'Charged twice this month'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertTrue(Customer::query()->where('account_number', 'SF7777777')->firstOrFail()->isProvisional());

        // Frontline raises tickets but does not work them.
        Livewire::test(ViewTicket::class, ['record' => $outage->id])
            ->assertActionHidden(TestAction::make('ticketRoute'))
            ->assertActionHidden(TestAction::make('ticketClose'))
            ->assertActionHidden(TestAction::make('ticketRaiseVisit'));
    }

    public function test_ticket_and_visit_actions_follow_the_desk_and_the_role(): void
    {
        $ticket = $this->ticket();
        $fieldSupport = $this->makeUser('CX Field Support', 'CX');

        // CX Tier II holds it: escalate, raise a visit, close — but not approve or dispatch.
        $this->actingAs($this->tier2);
        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->assertActionVisible(TestAction::make('ticketRoute'))
            ->assertActionVisible(TestAction::make('ticketClose'))
            ->assertActionHidden(TestAction::make('ticketApprove'))
            ->assertActionHidden(TestAction::make('ticketReturn'))
            ->assertActionHidden(TestAction::make('visitAssign'))
            ->callAction(TestAction::make('ticketRaiseVisit'), ['visit_type_id' => $this->supportType(), 'preferred_time' => 'today afternoon'])
            ->assertHasNoActionErrors();

        $visit = Outage::query()->siteVisits()->latest('id')->firstOrFail();
        $this->assertSame('SUP-1', $visit->ticket_number);
        $this->assertTrue($ticket->refresh()->status->isCode(StatusCode::TicketSiteVisit));

        // Dispatcher assigns from the visit page.
        $this->actingAs($this->dispatcher);
        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])
            ->assertActionVisible(TestAction::make('visitAssign'))
            ->assertActionHidden(TestAction::make('visitComplete'))
            ->assertActionHidden(TestAction::make('visitVerify'))
            ->callAction(TestAction::make('visitAssign'), ['technician_id' => $this->technician->id])
            ->assertHasNoActionErrors();
        $this->assertSame($this->technician->id, $visit->refresh()->technician_id);

        // Technician: arrive, then complete with the FAT number and both photos.
        $this->actingAs($this->technician);
        Storage::disk('local')->put($geo = 'outage-attachments/2026/10/geo.jpg', 'image');
        Storage::disk('local')->put($speed = 'outage-attachments/2026/10/speed.jpg', 'image');
        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])
            ->assertActionHidden(TestAction::make('visitAssign'))
            ->assertActionHidden(TestAction::make('ticketCancel'))
            ->assertActionVisible(TestAction::make('visitNotCompleted'))
            ->callAction(TestAction::make('visitArrive'))
            ->assertHasNoActionErrors();
        $this->assertTrue($visit->refresh()->status->isCode(StatusCode::VisitInProgress));

        $this->workflow->completeVisit($visit, $this->technician, [
            'final_reason_id' => FinalReason::query()->where('name', 'Splicing done at the FAT')->value('id'), 'resolution_notes' => 'Respliced',
            'fat_number' => 'FAT-7', 'geo_photo' => $geo, 'speed_photo' => $speed,
        ]);

        $this->actingAs($this->dispatcher);
        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])
            ->assertActionVisible(TestAction::make('visitVerify'))
            ->assertActionVisible(TestAction::make('visitRejectCompletion'))
            ->callAction(TestAction::make('visitVerify'), ['note' => 'Link online'])
            ->assertHasNoActionErrors();

        // CX Field Support confirms with the customer: the visit and the ticket that raised it close.
        $this->actingAs($fieldSupport);
        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])
            ->assertActionHidden(TestAction::make('ticketFollowUp'))
            ->callAction(TestAction::make('visitConfirm'), ['outcome' => 'confirmed', 'notes' => 'Client confirms restoration'])
            ->assertHasNoActionErrors();

        $this->assertTrue($visit->refresh()->status->isCode(StatusCode::VisitClosed));
        $this->assertTrue($ticket->refresh()->is_closed);

        // Closed tickets drop out of "Open" and show under "Closed".
        $this->actingAs($this->tier2);
        Livewire::test(ListTickets::class)->assertCanNotSeeTableRecords([$ticket])
            ->set('activeTab', 'closed')->assertCanSeeTableRecords([$ticket]);
    }

    public function test_booking_a_site_visit_from_the_form(): void
    {
        $this->actingAs($this->dispatcher);

        // Issue reported, the date and time agreed with the customer, customer availability, source, billing system ticket
        // number and narration are required.
        Livewire::test(CreateSiteVisit::class)
            ->fillForm([
                'visit_type_id' => $this->supportType(), 'account_number' => 'SF300', 'customer_name' => 'Direct Booking', 'calling_number' => '0772333444',
                'address' => 'Kireka', 'technician_id' => $this->technician->id, 'source' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['subcategory_id', 'scheduled_at', 'preferred_time', 'source', 'external_reference', 'description'])
            ->fillForm([
                'subcategory_id' => $this->sub('LOS Flashing Red'), 'scheduled_at' => now()->addHours(2)->startOfMinute()->toDateTimeString(),
                'preferred_time' => 'After 2 pm', 'source' => 'call', 'external_reference' => 'TKT-300', 'description' => 'Check the red light at the ONU',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $visit = Outage::query()->siteVisits()->latest('id')->firstOrFail();
        $this->assertSame('SUP-1', $visit->ticket_number);
        $this->assertSame($this->technician->id, $visit->current_assignee_id, 'a dispatcher can book straight to a technician');
        $ticket = Ticket::query()->where('outage_id', $visit->id)->firstOrFail();
        $this->assertSame(['call', 'TKT-300', 'After 2 pm', 'Check the red light at the ONU'], [$ticket->source, $ticket->external_reference, $ticket->preferred_time, $visit->description]);
        $this->assertNotNull($ticket->scheduled_at);
        $this->assertTrue($visit->status->isCode(StatusCode::VisitPendingScheduled));
    }

    public function test_customer_care_and_outage_screens_are_separate_audiences(): void
    {
        $ticket = $this->ticket();

        // A frontline agent sees tickets, not network outages.
        $this->actingAs($this->makeUser('CX Frontline', 'CX'));
        $this->get(TicketResource::getUrl('index'))->assertOk();
        $this->get(OutageResource::getUrl('index'))->assertForbidden();
        $this->get(TicketSettings::getUrl())->assertForbidden();
        $this->get(CustomerResource::getUrl())->assertOk();
        Livewire::test(ManageCustomers::class)->assertActionHidden(TestAction::make('importCustomers'))->assertActionHidden(TestAction::make('create'));

        // An infrastructure technician with no ticketing role cannot open Customer Care.
        $this->actingAs($this->makeUser('Infrastructure Team Leader', 'INFRA'));
        $this->get(TicketResource::getUrl('index'))->assertForbidden();
        $this->get(TicketResource::getUrl('view', ['record' => $ticket]))->assertForbidden();
        $this->get(TicketDashboard::getUrl())->assertForbidden();

        // The network dashboard and the outage list never show customer tickets.
        $this->actingAs($this->makeUser('NOC Manager', 'NOC'));
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee($ticket->ticket_number);
        $this->get(OutageResource::getUrl('index'))->assertOk()->assertDontSee($ticket->ticket_number);
    }

    public function test_settings_save_and_drive_the_sla_clock(): void
    {
        $this->actingAs($this->makeUser('Ticketing Manager', 'CX'));

        Livewire::test(TicketSettings::class)
            ->fillForm(['day_start' => '08:00', 'day_end' => '17:00', 'working_days' => [1, 2, 3, 4, 5, 6], 'sla_minutes' => 240, 'sla_tiers' => ['4', '8', '24'], 'followup_minutes' => 480])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Ticket settings saved');

        $settings = app(Settings::class);
        $settings->flush();
        $this->assertSame('08:00', $settings->get('ticketing.day_start'));
        $this->assertSame([4, 8, 24], $settings->get('ticketing.sla_tiers'));
        $this->assertSame([1, 2, 3, 4, 5, 6], $settings->get('ticketing.working_days'));
        $this->assertSame('08:00–17:00, Mon Tue Wed Thu Fri Sat', app(BusinessClock::class)->describe());

        // An end before the start is refused.
        Livewire::test(TicketSettings::class)->fillForm(['day_start' => '18:00', 'day_end' => '07:00'])->call('save')
            ->assertNotified('The working day must end after it starts');
        $this->assertSame('08:00', app(Settings::class)->get('ticketing.day_start'));
    }
}
