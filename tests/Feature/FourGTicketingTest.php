<?php

namespace Tests\Feature;

use App\Enums\NotificationEvent;
use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Filament\Resources\FourGTickets\FourGTicketResource;
use App\Filament\Resources\FourGTickets\Pages\ListFourGTickets;
use App\Filament\Resources\FourGTickets\Pages\ViewFourGTicket;
use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\TicketActions;
use App\Filament\Resources\Tickets\TicketForm;
use App\Models\Customer;
use App\Models\DeskRoute;
use App\Models\FinalReason;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vertical;
use App\Services\Mobility\VendorNotifier;
use App\Services\Outages\OutageWorkflow;
use Database\Seeders\FourGTicketingSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * 4G customer tickets: raised with Vertical 4G, CX Tier II â†’ 4G NOC â†’ IP / RF / Core team â†’ vendor â†’
 * 4G NOC validates â†’ CX Tier II confirms with the customer; updates due every 20 minutes; the
 * vertical keeps categories, reasons and desks apart.
 */
class FourGTicketingTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private User $frontline;

    private User $tier2;

    private User $noc;

    private User $rf;

    private int $fourG;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->seedReference();
        $this->seed(FourGTicketingSeeder::class);
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
        $this->fourG = Vertical::idFor(Vertical::MOBILITY_4G);
        $this->frontline = $this->makeUser('CX Frontline', 'CX');
        $this->tier2 = $this->makeUser('CX Tier II', 'CX');
        $this->noc = $this->makeUser('4G NOC', 'MOBILITY');
        $this->rf = $this->makeUser('4G RF Team', 'MOBILITY');
        Auth::setUser($this->frontline);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sub(string $name): int
    {
        return TicketSubcategory::query()->where('name', $name)->value('id');
    }

    private function fourGTicket(array $overrides = []): Outage
    {
        return $this->workflow->createTicket(array_merge([
            'vertical_id' => $this->fourG,
            'subcategory_id' => $this->sub('No service / no signal'),
            'account_number' => '4G100200',
            'customer_name' => 'Mobile Customer',
            'calling_number' => '0772000101',
            'description' => 'No signal since morning',
        ], $overrides), $this->frontline);
    }

    private function vendorLogs(Outage $outage, string $trigger): int
    {
        return NotificationLog::query()->where('outage_id', $outage->id)->where('event', VendorNotifier::EVENT)->get()
            ->filter(fn (NotificationLog $log) => ($log->meta['vendor_trigger'] ?? null) === $trigger)->count();
    }

    public function test_a_4g_ticket_goes_from_tier_two_to_the_noc_a_team_its_vendor_and_back_to_the_customer(): void
    {
        $vendor = Vendor::query()->create(['name' => 'Radio Vendor Ltd', 'code' => 'RADIO', 'vendor_type' => 'rf', 'email' => 'noc@radio.example', 'is_active' => true]);

        $outage = $this->fourGTicket();
        $this->assertTrue($outage->isFourGTicket());
        $this->assertSame(QueueType::CxTier2, $outage->current_queue);
        $this->assertSame($this->fourG, (int) Customer::query()->where('account_number', '4G100200')->value('vertical_id'), 'a new customer is of the ticket\'s vertical');

        // Tier II cannot fix it: the 4G NOC (never the FTTH NOC or a site visit).
        $this->assertSame(['ticket_4g_noc'], array_keys(array_intersect_key(TicketActions::routeOptions($outage), array_flip(['ticket_4g_noc', 'ticket_noc', 'visit_dispatch']))));
        $outage = $this->workflow->routeTicket($outage, $this->tier2, QueueType::Ticket4gNoc, 'No signal on two sectors');
        $this->assertSame(QueueType::Ticket4gNoc, $outage->current_queue);
        $this->assertSame($this->noc->id, $outage->current_assignee_id);

        // The NOC escalates to the RF team, which calls its vendor in.
        $outage = $this->workflow->routeTicket($outage, $this->noc, QueueType::TicketRf, 'Sector 2 down, RF to check');
        $this->assertSame($this->rf->id, $outage->current_assignee_id);
        $outage = $this->workflow->escalateTicketToVendor($outage, $this->rf, $vendor->id, 'Radio unit alarm, please attend');
        $this->assertSame($vendor->id, $outage->vendor_id);
        $this->assertSame(1, $this->vendorLogs($outage, 'ticket_created'));
        $this->assertNotNull(Ticket::query()->where('outage_id', $outage->id)->value('vendor_notified_at'));

        // The vendor comes back; the team records it and sends the ticket to the NOC to validate.
        $outage = $this->workflow->recordTicketVendorFeedback($outage, $this->rf, 'Radio unit replaced, sector up');
        $this->assertNotNull(Ticket::query()->where('outage_id', $outage->id)->value('vendor_feedback_at'));
        $outage = $this->workflow->routeTicket($outage, $this->rf, QueueType::Ticket4gNoc, 'Up: radio unit replaced by the vendor');
        $this->assertSame($this->noc->id, $outage->current_assignee_id);

        // NOC does not validate: back to the RF team; then it does: to Tier II to confirm with the customer.
        $outage = $this->workflow->returnTicket($outage, $this->noc, 'Sector still degraded, check again');
        $this->assertSame(QueueType::TicketRf, $outage->current_queue);
        $outage = $this->workflow->routeTicket($outage, $this->rf, QueueType::Ticket4gNoc, 'Antenna tilt corrected');
        $outage = $this->workflow->routeTicket($outage, $this->noc, QueueType::CxTier2, 'Validated: KPIs normal');
        $this->assertSame(QueueType::CxTier2, $outage->current_queue);
        $this->assertSame($this->tier2->id, $outage->current_assignee_id);

        // Tier II offers the 4G reasons only, and the FTTH ones are refused.
        $reasons = TicketActions::finalReasons('ticket', QueueType::CxTier2, $this->fourG);
        $this->assertContains('Resolved by the RF Team', $reasons);
        $this->assertNotContains('Billing query resolved', $reasons);

        try {
            $this->workflow->closeTicket($outage, $this->tier2, ['final_reason_id' => FinalReason::query()->where('vertical_id', '!=', $this->fourG)->where('applies_to', 'ticket')->value('id')]);
            $this->fail('An FTTH final reason closed a 4G ticket.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('final_reason_id', $e->errors());
        }

        $outage = $this->workflow->closeTicket($outage, $this->tier2, [
            'final_reason_id' => FinalReason::query()->where('name', 'Resolved by the RF Team')->value('id'), 'customer_confirmed' => true,
        ]);
        $this->assertSame(StatusCode::TicketClosed->value, $outage->status->code);
        $this->assertSame(1, $this->vendorLogs($outage, 'closed'), 'the vendor is told the ticket is closed');
    }

    public function test_an_update_is_due_every_20_minutes_and_a_silent_vendor_is_reminded(): void
    {
        $vendor = Vendor::query()->create(['name' => 'Core Vendor Ltd', 'code' => 'CORE1', 'vendor_type' => 'core', 'email' => 'core@vendor.example', 'is_active' => true]);
        $coreTeam = $this->makeUser('4G Core Team', 'MOBILITY');

        $outage = $this->fourGTicket();
        $outage = $this->workflow->routeTicket($outage, $this->tier2, QueueType::Ticket4gNoc, 'Cannot browse');
        $outage = $this->workflow->routeTicket($outage, $this->noc, QueueType::TicketCore, 'Core: APN issue');
        $outage = $this->workflow->escalateTicketToVendor($outage, $coreTeam, $vendor->id, 'APN not resolving');

        $due = fn () => NotificationLog::query()->where('outage_id', $outage->id)->where('event', NotificationEvent::TicketProgressDue->value)->where('recipient_user_id', $coreTeam->id)->count();

        Carbon::setTestNow(now()->addMinutes(19));
        $this->artisan('oms:ticket-progress')->assertSuccessful();
        $this->assertSame(0, $due());

        Carbon::setTestNow(now()->addMinutes(2));
        $this->artisan('oms:ticket-progress')->assertSuccessful();
        $this->assertSame(1, $due(), 'no update for 21 minutes: the holder is reminded');

        // An update restarts the clock.
        Carbon::setTestNow(now()->addMinutes(5));
        $this->workflow->addUpdate($outage, $coreTeam, 'Vendor engineer on site');
        Carbon::setTestNow(now()->addMinutes(15));
        $this->artisan('oms:ticket-progress')->assertSuccessful();
        $this->assertSame(1, $due());

        // An hour with nothing from the vendor: an e-mail reminder.
        Carbon::setTestNow(now()->addMinutes(40));
        $this->artisan('oms:ticket-progress')->assertSuccessful();
        $this->assertSame(1, $this->vendorLogs($outage, 'reminder'));
        $this->assertSame(2, $due());
    }

    public function test_the_vertical_keeps_categories_reasons_and_desks_apart(): void
    {
        $fourGSubs = TicketForm::subcategoryOptions(null, verticalId: $this->fourG);
        $ftthSubs = TicketForm::subcategoryOptions(null, verticalId: Vertical::idFor(Vertical::FTTH));
        $this->assertArrayHasKey($this->sub('No service / no signal'), $fourGSubs);
        $this->assertArrayNotHasKey($this->sub('LOS Flashing Red'), $fourGSubs);
        $this->assertArrayHasKey($this->sub('LOS Flashing Red'), $ftthSubs);
        $this->assertArrayNotHasKey($this->sub('No service / no signal'), $ftthSubs);

        // A 4G issue under the FTTH vertical is refused.
        $this->expectException(ValidationException::class);
        $this->fourGTicket(['vertical_id' => Vertical::idFor(Vertical::FTTH)]);
    }

    public function test_an_ftth_ticket_never_reaches_a_4g_desk(): void
    {
        $outage = $this->workflow->createTicket([
            'subcategory_id' => $this->sub('Slow Speeds'), 'account_number' => 'SF2577916', 'customer_name' => 'Fibre Customer',
            'calling_number' => '0772000001', 'description' => 'Slow',
        ], $this->frontline);

        $this->assertSame(Vertical::idFor(Vertical::FTTH), (int) $outage->vertical_id);
        $this->assertArrayHasKey(QueueType::TicketNoc->value, DeskRoute::targetsFor($outage));
        $this->assertArrayNotHasKey(QueueType::Ticket4gNoc->value, DeskRoute::targetsFor($outage));

        $this->expectException(ValidationException::class);
        $this->workflow->routeTicket($outage, $this->tier2, QueueType::Ticket4gNoc, 'Wrong desk');
    }

    public function test_the_4g_tickets_screen_and_the_vendor_buttons(): void
    {
        $vendor = Vendor::query()->create(['name' => 'IP Vendor Ltd', 'code' => 'IPV', 'vendor_type' => 'ip', 'email' => 'ip@vendor.example', 'is_active' => true]);
        $ipTeam = $this->makeUser('4G IP Team', 'MOBILITY');
        $fibre = $this->workflow->createTicket([
            'subcategory_id' => $this->sub('Slow Speeds'), 'account_number' => 'SF2577916', 'calling_number' => '0772000001', 'description' => 'Slow',
        ], $this->frontline);
        $outage = $this->fourGTicket();
        $outage = $this->workflow->routeTicket($outage, $this->tier2, QueueType::Ticket4gNoc, 'Cannot browse');
        $outage = $this->workflow->routeTicket($outage, $this->noc, QueueType::TicketIp, 'Routing issue');

        $this->actingAs($this->frontline);
        $this->assertFalse(FourGTicketResource::canViewAny(), 'CX frontline does not see the 4G NOC list');

        $this->actingAs($ipTeam);
        Livewire::test(ListFourGTickets::class)
            ->assertCanSeeTableRecords([$outage])
            ->assertCanNotSeeTableRecords([$fibre])
            ->set('activeTab', 'ip')->assertCanSeeTableRecords([$outage])
            ->set('activeTab', 'rf')->assertCanNotSeeTableRecords([$outage]);

        Livewire::test(ViewFourGTicket::class, ['record' => $outage->id])
            ->assertActionVisible(TestAction::make('ticketVendorEscalate'))
            ->assertActionHidden(TestAction::make('ticketVendorFeedback'))
            ->callAction(TestAction::make('ticketVendorEscalate'), ['vendor_id' => $vendor->id, 'note' => 'BGP session down'])
            ->assertHasNoActionErrors()
            ->assertActionVisible(TestAction::make('ticketVendorFeedback'))
            ->callAction(TestAction::make('ticketVendorFeedback'), ['feedback' => 'Session restored'])
            ->assertHasNoActionErrors();

        $this->assertSame($vendor->id, $outage->refresh()->vendor_id);
        $this->assertNotNull(Ticket::query()->where('outage_id', $outage->id)->value('vendor_feedback_at'));
    }

    public function test_the_raise_form_offers_the_categories_of_the_chosen_vertical(): void
    {
        $this->actingAs($this->frontline);
        $net = TicketCategory::query()->where('code', '4G-NET')->value('id');

        Livewire::test(CreateTicket::class)
            ->assertFormSet(['vertical_id' => Vertical::idFor(Vertical::FTTH)])
            ->fillForm(['vertical_id' => $this->fourG, 'category_id' => $net, 'subcategory_id' => $this->sub('Slow speeds'),
                'account_number' => '4G100300', 'customer_name' => 'Form Customer', 'calling_number' => '0772333444', 'source' => 'call',
                'external_reference' => 'TKT-4G1', 'preferred_time' => 'Any time', 'description' => 'Slow 4G'])
            ->call('create')
            ->assertHasNoFormErrors();

        $outage = Outage::query()->cxTickets()->latest('id')->firstOrFail();
        $this->assertTrue($outage->isFourGTicket());
        $this->assertSame('Slow speeds', $outage->ticket->subcategory->name);
    }
}
