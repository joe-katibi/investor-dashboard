<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Mail\ScheduledReportMail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DelayReason;
use App\Models\EmailTemplate;
use App\Models\Fdt;
use App\Models\FinalReason;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\Port;
use App\Models\Region;
use App\Models\Slot;
use App\Models\Ticket;
use App\Models\TicketDeskMove;
use App\Models\TicketEvent;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ScheduledReportService;
use App\Services\Reports\TicketReports;
use App\Services\Tickets\BusinessClock;
use App\Services\Tickets\CustomerImporter;
use App\Services\Tickets\TicketFollowUpMonitor;
use App\Services\Tickets\TicketMetrics;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Customer ticketing and site visits: the SLA business clock, the customer database, the desk
 * flows (billing, refund, credit, troubleshooting, NOC), site visits from dispatch to the
 * customer's confirmation, escalations, regions, the follow-up trigger and the reports.
 */
class TicketingTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private User $frontline;

    private User $tier2;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday, mid-morning: inside the 07:00–18:00 SLA day.
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
        $this->frontline = $this->makeUser('CX Frontline', 'CX');
        $this->tier2 = $this->makeUser('CX Tier II', 'CX');
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

    private function reason(string $name): int
    {
        return FinalReason::query()->where('name', $name)->value('id');
    }

    private function ticket(string $issue, array $overrides = [], ?User $by = null): Outage
    {
        return $this->workflow->createTicket(array_merge([
            'subcategory_id' => $this->sub($issue),
            'account_number' => 'SF2577916',
            'customer_name' => 'Jane Customer',
            'calling_number' => '0772000001',
            'alt_number_1' => '0752000002',
            'address' => 'Seguku near doctors hospital',
            'description' => 'Reported by the customer',
        ], $overrides), $by ?? $this->frontline);
    }

    private function visit(array $overrides = [], ?User $by = null): Outage
    {
        return $this->workflow->createSiteVisit(array_merge([
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'),
            'subcategory_id' => $this->sub('LOS Flashing Red'),
            'account_number' => 'SF2659301',
            'customer_name' => 'John Customer',
            'calling_number' => '0772000010',
            'primary_number' => '0772000011',
            'alt_number_1' => '0752000012',
            'address' => 'Lukuli water pump',
        ], $overrides), $by ?? $this->tier2);
    }

    private function details(Outage $outage): Ticket
    {
        return Ticket::query()->where('outage_id', $outage->id)->firstOrFail();
    }

    private function code(Outage $outage): ?string
    {
        return $outage->refresh()->status->code;
    }

    private function photo(string $name): string
    {
        Storage::disk('local')->put($path = 'outage-attachments/2026/10/'.$name, 'image');

        return $path;
    }

    // ------------------------------------------------------------------ SLA clock

    public function test_the_sla_clock_only_counts_the_working_day(): void
    {
        $clock = app(BusinessClock::class);

        // Raised after 18:00: starts counting at 07:00 the next day.
        $this->assertSame('2026-10-06 10:00:00', $clock->add(Carbon::parse('2026-10-05 19:30'), 180)->toDateTimeString());
        // Raised at 17:00 with a 3-hour target: one hour today, two tomorrow.
        $this->assertSame('2026-10-06 09:00:00', $clock->add(Carbon::parse('2026-10-05 17:00'), 180)->toDateTimeString());
        $this->assertSame('2026-10-05 13:00:00', $clock->add(Carbon::parse('2026-10-05 10:00'), 180)->toDateTimeString());
        $this->assertSame(120, $clock->minutesBetween(Carbon::parse('2026-10-05 17:00'), Carbon::parse('2026-10-06 08:00')));
        $this->assertSame(0, $clock->minutesBetween(Carbon::parse('2026-10-05 19:00'), Carbon::parse('2026-10-06 06:00')));

        // Weekends off, when configured.
        app(Settings::class)->set(['ticketing.working_days' => [1, 2, 3, 4, 5]]);
        $this->assertSame('2026-10-12 09:00:00', $clock->add(Carbon::parse('2026-10-09 17:00'), 180)->toDateTimeString());

        app(Settings::class)->set(['ticketing.sla_clock' => 'wall']);
        $this->assertSame('2026-10-05 22:30:00', $clock->add(Carbon::parse('2026-10-05 19:30'), 180)->toDateTimeString());
    }

    // ------------------------------------------------------------------ customers

    public function test_a_ticket_takes_the_customer_and_position_from_the_customer_database(): void
    {
        $region = Region::query()->create(['name' => 'Central', 'is_active' => true]);
        $olt = $this->makeOlt('Kabowa');
        $olt->update(['region_id' => $region->id]);
        $fdt = Fdt::query()->create(['olt_id' => $olt->id, 'name' => 'FDT 6', 'is_active' => true]);
        $slot = Slot::query()->create(['fdt_id' => $fdt->id, 'name' => '3', 'is_active' => true]);
        $port = Port::query()->create(['slot_id' => $slot->id, 'name' => '15', 'is_active' => true]);
        $customer = Customer::query()->create([
            'account_number' => 'sf2577916', 'name' => 'Jane On File', 'primary_phone' => '0700111222', 'olt_id' => $olt->id, 'fdt_id' => $fdt->id,
            'slot_id' => $slot->id, 'port_id' => $port->id, 'bandwidth' => '20 Mbps', 'source' => Customer::SOURCE_IMPORT, 'verified_at' => now(),
        ]);

        $outage = $this->ticket('Slow Speeds', ['account_number' => ' sf2577916 ', 'customer_name' => null]);
        $ticket = $this->details($outage);

        $this->assertMatchesRegularExpression('/^TKT-\d{6}$/', $outage->ticket_number);
        $this->assertTrue($outage->isCxTicket());
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertSame('SF2577916', $ticket->account_number);
        $this->assertSame('Jane On File', $ticket->customer_name);
        $this->assertSame([$olt->id, $fdt->id, $slot->id, $port->id], [$ticket->olt_id, $ticket->fdt_id, $ticket->slot_id, $ticket->port_id]);
        $this->assertSame($region->id, $ticket->region_id, 'the region comes from the OLT');
        $this->assertSame('0700111222', $ticket->primary_number);
        $this->assertSame('Kabowa › FDT 6 › Slot 3 › Port 15', $ticket->topologyPath());

        // Routed to the subcategory's first desk and handed to the next available person there.
        $this->assertSame(QueueType::CxTier2, $ticket->desk);
        $this->assertSame(QueueType::CxTier2, $outage->current_queue);
        $this->assertSame($this->tier2->id, $outage->current_assignee_id);
        $this->assertSame(StatusCode::TicketOpen->value, $this->code($outage));

        // 3-hour target on the business clock.
        $this->assertSame('2026-10-05 13:00:00', $outage->sla_due_at->toDateTimeString());
        $this->assertSame(SlaState::Compliant, $outage->sla_state);
    }

    public function test_an_unknown_account_is_captured_by_hand_and_completed_by_the_customer_import(): void
    {
        $outage = $this->ticket('LOS Flashing Red', ['account_number' => 'SF9000001', 'customer_name' => 'Typed Name']);
        $ticket = $this->details($outage);
        $customer = Customer::query()->where('account_number', 'SF9000001')->firstOrFail();

        $this->assertTrue($customer->isProvisional());
        $this->assertSame('Typed Name', $customer->name);
        $this->assertSame('0772000001', $customer->primary_phone);
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertNull($ticket->olt_id);

        $this->makeOlt('Nsambya');
        $path = tempnam(sys_get_temp_dir(), 'cust').'.csv';
        file_put_contents($path, "Alias Name,ONU Name,OLT,FDT,Slot,Port,Bandwidth,Region\n"
            ."Real Customer Ltd,sf9000001,nsambya,fdt 2,4,7,50 Mbps,South\n"
            ."Second Customer,SF9000002,Kireka,FDT 1,1,1,10 Mbps,East\n");

        $preview = app(CustomerImporter::class)->import($path, dryRun: true);
        $this->assertSame(1, $preview['created']);
        $this->assertSame(1, Customer::query()->count(), 'a preview saves nothing');

        $report = app(CustomerImporter::class)->import($path);
        @unlink($path);

        $this->assertSame(['rows' => 2, 'created' => 1, 'updated' => 1], array_intersect_key($report, ['rows' => 1, 'created' => 1, 'updated' => 1]));
        $this->assertSame(['olts' => 1, 'fdts' => 2, 'slots' => 2, 'ports' => 2], $report['topology'], 'the existing OLT is reused');
        $this->assertSame(1, $report['tickets_linked']);

        $customer->refresh();
        $this->assertFalse($customer->isProvisional());
        $this->assertSame('Real Customer Ltd', $customer->name);
        $this->assertSame('Nsambya › FDT 2 › Slot 4 › Port 7', $customer->topologyPath());
        $this->assertSame('0772000001', $customer->primary_phone, 'details the file does not carry are kept');

        $ticket->refresh();
        $this->assertSame($customer->olt_id, $ticket->olt_id);
        $this->assertSame(Region::query()->where('name', 'South')->value('id'), $ticket->region_id);

        // Importing the same file again changes nothing.
        $again = app(CustomerImporter::class)->import($this->csv("Alias Name,ONU Name,OLT,FDT,Slot,Port,Bandwidth,Region\nReal Customer Ltd,sf9000001,nsambya,fdt 2,4,7,50 Mbps,South\n"));
        $this->assertSame([0, 0, 1], [$again['created'], $again['updated'], $again['unchanged']]);
    }

    private function csv(string $content): string
    {
        file_put_contents($path = tempnam(sys_get_temp_dir(), 'cust').'.csv', $content);

        return $path;
    }

    // ------------------------------------------------------------------ customer ticket flows

    public function test_billing_ticket_is_escalated_to_the_manager_and_closed_by_billing(): void
    {
        $billing = $this->makeUser('Billing Agent', 'BILLING');
        $manager = $this->makeUser('Billing Manager', 'BILLING');

        $outage = $this->ticket('Wrong bill / double charge');
        $this->assertSame($billing->id, $outage->current_assignee_id);

        // Not at the manager's desk yet: the manager cannot act on it.
        $this->assertFalse($manager->can('ticketApprove', $outage));

        $outage = $this->workflow->routeTicket($outage, $billing, QueueType::BillingManager, 'Customer disputes two months');
        $this->assertSame(StatusCode::TicketPendingApproval->value, $this->code($outage));
        $this->assertSame($manager->id, $outage->current_assignee_id);
        $this->assertSame(1, $this->details($outage)->escalation_count);

        $outage = $this->workflow->approveTicket($outage, $manager, 'Reverse the second charge', 45000);
        $ticket = $this->details($outage);
        $this->assertSame(QueueType::Billing, $ticket->desk, 'a Billing Manager approval returns to the agent');
        $this->assertSame(Ticket::APPROVAL_APPROVED, $ticket->approval_state);
        $this->assertSame('45000.00', $ticket->amount);
        $this->assertNotNull(TicketDeskMove::query()->where('outage_id', $outage->id)->where('to_desk', 'billing_manager')->value('returned_at'));

        $outage = $this->workflow->closeTicket($outage, $billing, ['final_reason_id' => $this->reason('Billing query resolved'), 'resolution_notes' => 'Charge reversed', 'customer_confirmed' => true]);
        $this->assertSame(StatusCode::TicketClosed->value, $this->code($outage));
        $this->assertTrue($outage->is_closed);
        $this->assertSame(SlaState::Met, $outage->sla_state);
        $this->assertNull($outage->current_queue);
        $this->assertStringContainsString('Billing query resolved', $outage->final_reason);
    }

    public function test_a_desk_can_only_send_a_ticket_where_the_routes_allow_and_only_its_holder_may(): void
    {
        $billing = $this->makeUser('Billing Agent', 'BILLING');
        $other = $this->makeUser('Billing Agent', 'BILLING');
        $outage = $this->ticket('Payment not reflected');
        $holder = $outage->current_assignee_id === $billing->id ? $billing : $other;
        $bystander = $holder->is($billing) ? $other : $billing;

        try {
            $this->workflow->routeTicket($outage, $bystander, QueueType::BillingManager, 'Not mine');
            $this->fail('Someone who does not hold the ticket moved it.');
        } catch (AuthorizationException) {
        }

        $this->expectException(ValidationException::class);
        $this->workflow->routeTicket($outage, $holder, QueueType::Design, 'Billing cannot send to Design');
    }

    public function test_refund_is_assessed_by_commercial_then_paid_by_billing_or_declined(): void
    {
        $commercial = $this->makeUser('Commercial / Sales Admin', 'COMMERCIAL');
        $billing = $this->makeUser('Billing Agent', 'BILLING');
        $callback = $this->makeUser('CX Follow-up Agent', 'CX');

        $outage = $this->ticket('Refund request', ['amount' => 120000]);
        $this->assertSame(QueueType::Commercial, $outage->current_queue);
        $this->assertSame(StatusCode::TicketPendingApproval->value, $this->code($outage));
        $this->assertFalse($commercial->can('ticketClose', $outage), 'a request awaiting approval is approved or declined first');

        $outage = $this->workflow->approveTicket($outage, $commercial, 'Customer was double billed');
        $this->assertSame(QueueType::Billing, $outage->current_queue, 'approved refunds go to Billing to be paid');
        $this->assertSame($billing->id, $outage->current_assignee_id);

        // "Refund not done – customer decided not to refund" is just another final reason.
        $outage = $this->workflow->closeTicket($outage, $billing, ['final_reason_id' => $this->reason('Refund not done - customer withdrew the request')]);
        $this->assertSame(StatusCode::TicketClosed->value, $this->code($outage));

        // Declined at the first desk: CX calls the customer and closes it as declined.
        $second = $this->ticket('Refund request', ['account_number' => 'SF2000002']);
        $second = $this->workflow->declineTicket($second, $commercial, 'Outside the refund policy');
        $this->assertSame(QueueType::CxCallback, $second->current_queue);
        $this->assertSame(StatusCode::TicketPendingCustomer->value, $this->code($second));

        $this->workflow->recordTicketFollowUp($second, $callback, ['outcome' => 'updated', 'notes' => 'Explained the policy']);
        $second = $this->workflow->closeTicket($second, $callback, ['final_reason_id' => $this->reason('Request declined - customer advised')]);
        $this->assertSame(StatusCode::TicketDeclined->value, $this->code($second));
        $this->assertSame(SlaState::NotApplicable, $second->sla_state);
    }

    public function test_credit_request_is_vetted_by_tier_two_approved_by_the_cx_manager_and_applied_by_billing(): void
    {
        $manager = $this->makeUser('CX Manager', 'CX');
        $billing = $this->makeUser('Billing Agent', 'BILLING');

        $outage = $this->ticket('Credit request - downtime');
        $this->assertSame(QueueType::CxTier2, $outage->current_queue);

        $outage = $this->workflow->routeTicket($outage, $this->tier2, QueueType::CxManager, 'Usage checked: 3 days without service');
        $this->assertSame(StatusCode::TicketPendingApproval->value, $this->code($outage));

        $outage = $this->workflow->approveTicket($outage, $manager, 'Approved: 3 days', 15000);
        $this->assertSame(QueueType::Billing, $outage->current_queue);

        $outage = $this->workflow->closeTicket($outage, $billing, ['final_reason_id' => $this->reason('Credit applied to the account')]);
        $this->assertSame(StatusCode::TicketClosed->value, $this->code($outage));
        $this->assertSame($manager->id, $this->details($outage)->approval_by);
    }

    public function test_troubleshooting_goes_to_noc_and_back_then_a_site_visit_is_raised(): void
    {
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');

        $outage = $this->ticket('Slow Speeds');
        $outage = $this->workflow->routeTicket($outage, $this->tier2, QueueType::TicketNoc, 'Line stats needed');
        $this->assertSame(StatusCode::TicketEscalated->value, $this->code($outage));
        $this->assertSame($noc->id, $outage->current_assignee_id);

        $outage = $this->workflow->returnTicket($outage, $noc, 'Optical power -27 dBm, needs a site visit');
        $this->assertSame(QueueType::CxTier2, $outage->current_queue);
        $this->assertSame($this->tier2->id, $outage->current_assignee_id);
        $this->assertNotNull($this->details($outage)->returned_at);

        $visit = $this->workflow->raiseSiteVisit($outage, $this->tier2, [
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'), 'preferred_time' => 'available today', 'note' => 'Customer is home',
        ]);

        $this->assertSame('SUP-1', $visit->ticket_number);
        $this->assertTrue($visit->isSiteVisit());
        $this->assertSame($outage->id, $this->details($visit)->parent_outage_id);
        $this->assertSame('SF2577916', $this->details($visit)->account_number, 'customer details are carried over');
        $this->assertSame(QueueType::VisitDispatch, $visit->current_queue);
        $this->assertSame($dispatcher->id, $visit->current_assignee_id);

        // The customer ticket waits for the visit.
        $this->assertSame(StatusCode::TicketSiteVisit->value, $this->code($outage));
        $this->assertNull($outage->current_queue);
    }

    // ------------------------------------------------------------------ site visits

    public function test_site_visit_runs_from_dispatch_to_the_customers_confirmation_and_closes_the_parent_ticket(): void
    {
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH', attributes: ['phone' => '0700555666']);
        $fieldSupport = $this->makeUser('CX Field Support', 'CX');

        $parent = $this->ticket('LOS Flashing Red');
        $visit = $this->workflow->raiseSiteVisit($parent, $this->tier2, ['visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id')]);
        $this->assertSame(StatusCode::VisitOpen->value, $this->code($visit));

        $visit = $this->workflow->assignVisit($visit, $dispatcher, ['technician_id' => $technician->id, 'note' => 'Go first thing']);
        $this->assertSame(StatusCode::VisitPendingScheduled->value, $this->code($visit));
        $this->assertSame($technician->id, $visit->technician_id);
        $this->assertNotNull($this->details($visit)->first_assigned_at);

        // The customer is texted on every number of the ticket.
        $sms = NotificationLog::query()->where('outage_id', $visit->id)->where('event', 'ticket_customer_sms')->get();
        $this->assertCount(2, $sms);
        $this->assertStringContainsString($technician->name, $sms->first()->body);
        $this->assertStringContainsString('0700555666', $sms->first()->body);
        $this->assertStringContainsString($visit->ticket_number, $sms->first()->body);
        $this->assertStringContainsString('today', $sms->first()->body);

        // Only the technician holding the visit works it.
        $this->assertFalse($dispatcher->can('visitEnRoute', $visit));
        $visit = $this->workflow->visitEnRoute($visit, $technician);
        $this->assertSame(StatusCode::VisitInProgress->value, $this->code($visit));
        $visit = $this->workflow->visitArrive($visit, $technician);
        $visit = $this->workflow->visitPause($visit, $technician, 'Waiting for the customer to open the gate');
        Carbon::setTestNow(now()->addMinutes(20));
        $visit = $this->workflow->visitResume($visit, $technician);
        $this->assertSame(1200, $this->details($visit)->paused_seconds);

        // Closing needs the FAT number and both photos.
        try {
            $this->workflow->completeVisit($visit, $technician, ['final_reason_id' => $this->reason('Splicing done at the FAT'), 'resolution_notes' => 'Respliced']);
            $this->fail('Completed without evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fat_number', $exception->errors());
        }

        Carbon::setTestNow(now()->addMinutes(40));
        $visit = $this->workflow->completeVisit($visit, $technician, [
            'final_reason_id' => $this->reason('Splicing done at the FAT'), 'resolution_notes' => 'Broken core respliced', 'fat_number' => 'FAT-112',
            'geo_photo' => $this->photo('geo.jpg'), 'speed_photo' => $this->photo('speed.jpg'), 'drop_cable_m' => 40,
        ]);
        $ticket = $this->details($visit);
        $this->assertSame(StatusCode::VisitPendingVerification->value, $this->code($visit));
        $this->assertSame(QueueType::VisitDispatch, $visit->current_queue, 'back to the dispatcher for the final close');
        $this->assertSame('FAT-112', $ticket->fat_number);
        $this->assertSame(['geo_photo', 'speed_test'], $visit->attachments()->reorder('id')->pluck('kind')->all());
        $this->assertSame(2400, $ticket->onPremiseSeconds(), '60 minutes on site less the 20-minute pause');

        $visit = $this->workflow->verifyVisit($visit, $dispatcher, 'Link online');
        $this->assertSame(StatusCode::VisitPendingConfirmation->value, $this->code($visit));
        $this->assertSame($fieldSupport->id, $visit->current_assignee_id);

        $visit = $this->workflow->confirmVisit($visit, $fieldSupport, ['outcome' => 'confirmed', 'notes' => 'Client confirms restoration']);
        $this->assertSame(StatusCode::VisitClosed->value, $this->code($visit));
        $this->assertTrue($this->details($visit)->customer_confirmed);
        $this->assertSame(SlaState::Met, $visit->sla_state);
        $this->assertEquals($ticket->completed_at, $visit->end_time, 'the SLA ends when the work was completed');

        // The customer ticket that raised it closes with it.
        $this->assertSame(StatusCode::TicketClosed->value, $this->code($parent));
        $this->assertStringContainsString($visit->ticket_number, $parent->final_reason);

        // The whole journey is on the event log and the audit trail.
        $events = TicketEvent::query()->where('outage_id', $visit->id)->orderBy('id')->pluck('event')->all();
        foreach (['created', 'queued', 'assigned', 'scheduled', 'customer_sms', 'en_route', 'arrived', 'paused', 'resumed', 'completed', 'verified', 'confirmed', 'closed'] as $event) {
            $this->assertContains($event, $events);
        }
        $this->assertTrue(AuditLog::query()->ticketing()->where('event', 'tkt_completed')->exists());
        $this->assertTrue(AuditLog::query()->ticketing()->where('event', 'tkt_status_changed')->exists());
    }

    public function test_visit_types_number_their_own_visits(): void
    {
        $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $type = fn (string $prefix) => VisitType::query()->where('code_prefix', $prefix)->value('id');

        $this->assertSame('SUP-1', $this->visit()->ticket_number);
        $this->assertSame('SUP-2', $this->visit(['account_number' => 'SF1'])->ticket_number);
        $this->assertSame('INS-1', $this->visit(['visit_type_id' => $type('INS'), 'account_number' => 'SF2'])->ticket_number);
        $this->assertSame('WiE-1', $this->visit(['visit_type_id' => $type('WiE'), 'account_number' => 'SF3'])->ticket_number);
        $this->assertSame('SHI-1', $this->visit(['visit_type_id' => $type('SHI'), 'account_number' => 'SF4'])->ticket_number);

        // Shifting has its own (72-hour) target.
        $shifting = Outage::query()->where('ticket_number', 'SHI-1')->firstOrFail();
        $this->assertSame(4320, app(BusinessClock::class)->minutesBetween($shifting->start_time, $shifting->sla_due_at));

        $this->expectException(ValidationException::class);
        $this->visit(['scheduled_at' => now()->subDay()]);
    }

    public function test_a_visit_that_cannot_be_completed_is_escalated_and_closes_as_closed_escalated(): void
    {
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH');
        $infraDispatcher = $this->makeUser('Infra Support Dispatcher', 'INFRA');
        $infraTechnician = $this->makeUser('Infra Support Technician', 'INFRA');
        app(Settings::class)->set(['ticketing.confirm_closure' => false]);

        $visit = $this->visit();
        $visit = $this->workflow->assignVisit($visit, $dispatcher, ['technician_id' => $technician->id]);
        $visit = $this->workflow->visitArrive($visit, $technician);
        $visit = $this->workflow->visitNotCompleted($visit, $technician, 'FAT has no power');
        $this->assertSame(QueueType::VisitDispatch, $visit->current_queue);
        $this->assertSame(StatusCode::VisitOpen->value, $this->code($visit));

        $visit = $this->workflow->routeTicket($visit, $dispatcher, QueueType::InfraSupportDispatch, 'FAT not powered');
        $this->assertSame(StatusCode::VisitPendingEscalated->value, $this->code($visit));
        $this->assertSame(DelayReason::idForCode(DelayReason::ESCALATED_INFRA), $this->details($visit)->delay_reason_id);

        $visit = $this->workflow->routeTicket($visit, $infraDispatcher, QueueType::InfraSupportTechnician, 'Power the FAT', userId: $infraTechnician->id);
        $this->assertSame($infraTechnician->id, $visit->current_assignee_id);

        $visit = $this->workflow->returnTicket($visit, $infraTechnician, 'FAT powered');
        $this->assertSame(QueueType::InfraSupportDispatch, $visit->current_queue, 'a technician returns to his own dispatcher');
        $visit = $this->workflow->returnTicket($visit, $infraDispatcher, 'FAT powered, ready for the support technician');
        $this->assertSame(QueueType::VisitDispatch, $visit->current_queue);

        // The dispatcher sends a technician again, who completes it; with confirmation off the dispatcher closes.
        $visit = $this->workflow->assignVisit($visit, $dispatcher, ['technician_id' => $technician->id]);
        $visit = $this->workflow->visitArrive($visit, $technician);
        $visit = $this->workflow->completeVisit($visit, $technician, [
            'final_reason_id' => $this->reason('FAT powered'), 'resolution_notes' => 'Link restored', 'fat_number' => 'FAT-9',
            'geo_photo' => $this->photo('g.jpg'), 'speed_photo' => $this->photo('s.jpg'),
        ]);
        $visit = $this->workflow->verifyVisit($visit, $dispatcher);

        $this->assertSame(StatusCode::VisitClosedEscalated->value, $this->code($visit));
        $this->assertSame(2, TicketDeskMove::query()->where('outage_id', $visit->id)->where('kind', 'route')->whereNotNull('returned_at')->count());
    }

    public function test_unreachable_customers_go_to_cx_field_support_who_reschedule_and_text_the_customer(): void
    {
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH');
        $fieldSupport = $this->makeUser('CX Field Support', 'CX');

        $visit = $this->visit();
        $visit = $this->workflow->assignVisit($visit, $dispatcher, ['technician_id' => $technician->id]);
        $visit = $this->workflow->visitNotCompleted($visit, $technician, 'Nobody at home, phone off', DelayReason::idForCode(DelayReason::UNREACHABLE), toFieldSupport: true);

        $this->assertSame(QueueType::CxFieldSupport, $visit->current_queue);
        $this->assertSame(StatusCode::VisitPendingCustomer->value, $this->code($visit));
        $this->assertSame($fieldSupport->id, $visit->current_assignee_id);

        $before = NotificationLog::query()->where('event', 'ticket_customer_sms')->count();
        $visit = $this->workflow->rescheduleVisit($visit, $fieldSupport, now()->addDay()->setTime(10, 0), 'Customer asked for tomorrow morning');
        $ticket = $this->details($visit);

        $this->assertSame('2026-10-06 10:00:00', $ticket->scheduled_at->toDateTimeString());
        $this->assertSame(1, $ticket->reschedule_count);
        $this->assertSame(QueueType::VisitDispatch, $visit->current_queue, 'back with the dispatcher, who did not hand it over himself');
        $this->assertSame(3, NotificationLog::query()->where('event', 'ticket_customer_sms')->count() - $before, 'calling, primary and alternative numbers');
        $this->assertStringContainsString('Tue 06 Oct 2026, 10:00', NotificationLog::query()->where('event', 'ticket_customer_sms')->latest('id')->value('body'));

        // Booked for tomorrow without a named technician: the technician queue only offers it tomorrow.
        $visit = $this->workflow->assignVisit($visit, $dispatcher, []);
        $this->assertSame(StatusCode::VisitPendingWithoutTeam->value, $this->code($visit));
        $this->assertNull($visit->current_assignee_id);
        $this->assertSame('2026-10-06 00:00:00', $visit->openQueueEntries()->where('queue_type', 'visit_technician')->firstOrFail()->eligible_from->toDateTimeString());
    }

    public function test_the_customer_can_send_a_closed_visit_back(): void
    {
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH');
        $fieldSupport = $this->makeUser('CX Field Support', 'CX');

        $visit = $this->visit();
        $visit = $this->workflow->assignVisit($visit, $dispatcher, ['technician_id' => $technician->id]);
        $visit = $this->workflow->visitArrive($visit, $technician);
        $visit = $this->workflow->completeVisit($visit, $technician, [
            'final_reason_id' => $this->reason('Faulty ONT replaced'), 'resolution_notes' => 'ONU swapped', 'fat_number' => 'FAT-3',
            'geo_photo' => $this->photo('g.jpg'), 'speed_photo' => $this->photo('s.jpg'),
        ]);
        $visit = $this->workflow->verifyVisit($visit, $dispatcher);
        $visit = $this->workflow->confirmVisit($visit, $fieldSupport, ['outcome' => 'not_resolved', 'notes' => 'Still red light']);

        $this->assertFalse($visit->is_closed);
        $this->assertSame(QueueType::VisitDispatch, $visit->current_queue);
        $this->assertSame(StatusCode::VisitOpen->value, $this->code($visit));
        $this->assertSame(1, $visit->reopen_count);
        $this->assertNull($this->details($visit)->completed_at);

        // A closed ticket can be reopened too, with a reason.
        $visit = $this->workflow->closeTicket($visit, $dispatcher, ['final_reason_id' => $this->reason('Client confirmed uptime upon call - Before site visit')]);
        $this->assertTrue($visit->is_closed);
        $manager = $this->makeUser('Ticketing Manager', 'CX');
        $visit = $this->workflow->reopenTicket($visit, $manager, 'Customer called back: down again');
        $this->assertFalse($visit->is_closed);
        $this->assertSame(2, $visit->reopen_count);
        $this->assertSame(1, $visit->reopenings()->count());
    }

    // ------------------------------------------------------------------ outages, regions, intervention

    public function test_a_los_ticket_is_linked_to_the_ongoing_outage_on_the_customers_position(): void
    {
        $olt = $this->makeOlt('Naguru');
        $fdt = Fdt::query()->create(['olt_id' => $olt->id, 'name' => 'FDT 1', 'is_active' => true]);
        $other = Fdt::query()->create(['olt_id' => $olt->id, 'name' => 'FDT 2', 'is_active' => true]);
        Customer::query()->create(['account_number' => 'SF500', 'name' => 'Affected', 'olt_id' => $olt->id, 'fdt_id' => $fdt->id, 'source' => 'import', 'verified_at' => now()]);
        Customer::query()->create(['account_number' => 'SF501', 'name' => 'Elsewhere', 'olt_id' => $olt->id, 'fdt_id' => $other->id, 'source' => 'import', 'verified_at' => now()]);

        // Reported, not yet confirmed by NOC: it still counts.
        $network = $this->reportOutage($this->makeUser('NOC Tier I', 'NOC'), ['olt_ids' => [$olt->id], 'fdt_ids' => [$fdt->id]]);

        $affected = $this->ticket('LOS Flashing Red', ['account_number' => 'SF500']);
        $this->assertSame($network->id, $this->details($affected)->linked_outage_id);
        $this->assertSame(DelayReason::idForCode(DelayReason::OUTAGE), $this->details($affected)->delay_reason_id);
        $this->assertTrue($affected->updates()->where('body', 'like', "%{$network->ticket_number}%")->where('body', 'like', '%not yet confirmed by NOC%')->exists());

        $elsewhere = $this->ticket('LOS Flashing Red', ['account_number' => 'SF501']);
        $this->assertNull($this->details($elsewhere)->linked_outage_id, 'another FDT of the same OLT is not affected');

        // Slow speeds does not check for outages.
        $this->assertNull($this->details($this->ticket('Slow Speeds', ['account_number' => 'SF500']))->linked_outage_id);
    }

    public function test_dispatchers_locked_to_a_region_only_receive_its_visits(): void
    {
        $north = Region::query()->create(['name' => 'North', 'is_active' => true]);
        $south = Region::query()->create(['name' => 'South', 'is_active' => true]);
        $northDispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $southDispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $northDispatcher->regions()->sync([$north->id]);
        $southDispatcher->regions()->sync([$south->id]);

        foreach (range(1, 3) as $i) {
            $this->assertSame($southDispatcher->id, $this->visit(['account_number' => "SFS{$i}", 'region_id' => $south->id])->current_assignee_id);
        }

        $this->assertSame($northDispatcher->id, $this->visit(['account_number' => 'SFN1', 'region_id' => $north->id])->current_assignee_id);
    }

    public function test_team_leader_intervention_takes_the_ticket_over_and_is_timed(): void
    {
        $leader = $this->makeUser('CX Team Leader', 'CX');
        $outage = $this->ticket('Intermittent Connection');
        $this->assertSame($this->tier2->id, $outage->current_assignee_id);

        $outage = $this->workflow->startIntervention($outage, $leader, 'Customer is escalating');
        $this->assertSame($leader->id, $outage->current_assignee_id);
        $this->assertTrue($leader->can('ticketEndIntervention', $outage));

        Carbon::setTestNow(now()->addMinutes(15));
        $outage = $this->workflow->endIntervention($outage, $leader, 'Calmed down, back to the agent');
        $ticket = $this->details($outage);

        $this->assertSame(900, $ticket->intervention_seconds);
        $this->assertNotNull($ticket->intervention_ended_at);
        $this->assertSame($this->tier2->id, $outage->refresh()->current_assignee_id, 'handed back to the queue');
        $this->assertTrue(TicketEvent::query()->where('outage_id', $outage->id)->where('event', 'intervention_started')->exists());
    }

    // ------------------------------------------------------------------ follow-up, notifications, reports

    public function test_tickets_pending_past_the_follow_up_time_are_queued_for_a_call_and_listed_for_credit(): void
    {
        $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $billingManager = $this->makeUser('Billing Manager', 'BILLING', attributes: ['email' => 'billing.manager@example.com']);
        app(Settings::class)->set(['ticketing.credit_emails' => ['credits@example.com']]);
        $visit = $this->visit();
        $monitor = app(TicketFollowUpMonitor::class);

        // 8 SLA hours by 18:00, and the evening does not count.
        Carbon::setTestNow('2026-10-05 22:00:00');
        $this->assertSame(['flagged' => 0, 'notified' => 0, 'aftercare' => 0], $monitor->tick());

        // 08:30 next morning: 9.5 SLA hours.
        Carbon::setTestNow('2026-10-06 08:30:00');
        $this->assertSame(['flagged' => 1, 'notified' => 1, 'aftercare' => 0], $monitor->tick());

        $ticket = $this->details($visit);
        $this->assertNotNull($ticket->follow_up_due_at);
        $this->assertNotNull($ticket->credit_ready_at);
        $this->assertNotNull($ticket->credit_notified_at);
        $this->assertTrue($visit->openQueueEntries()->where('queue_type', 'ticket_follow_up')->exists());
        $this->assertSame(QueueType::VisitDispatch, $visit->refresh()->current_queue, 'the follow-up runs alongside: the visit keeps its desk');

        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail) => str_contains($mail->subjectLine, '1 ticket ready for credit')
            && $mail->hasTo('credits@example.com') && $mail->hasTo('billing.manager@example.com') && str_contains($mail->bodyHtml, $visit->ticket_number));
        $this->assertSame(1, $billingManager->notifications()->count());

        // Nothing is repeated on the next run.
        $this->assertSame(['flagged' => 0, 'notified' => 0, 'aftercare' => 0], $monitor->tick());

        // The follow-up call clears the queue item.
        $agent = $this->makeUser('CX Follow-up Agent', 'CX');
        $this->workflow->recordTicketFollowUp($visit, $agent, ['outcome' => 'not_picking']);
        $this->assertFalse($visit->openQueueEntries()->where('queue_type', 'ticket_follow_up')->exists());
        $this->assertSame(DelayReason::query()->where('code', 'D03')->value('id'), $this->details($visit)->delay_reason_id);
    }

    public function test_outage_notification_rules_and_alert_lists_never_fire_for_customer_tickets(): void
    {
        app(Settings::class)->set(['alerts.emails' => ['noc-alerts@example.com'], 'alerts.phones' => ['+256700000001']]);
        $this->makeUser('NOC Manager', 'NOC');

        $outage = $this->ticket('Slow Speeds');

        $this->assertSame(0, NotificationLog::query()->where('outage_id', $outage->id)->whereNotNull('notification_rule_id')->count());
        $this->assertSame(0, NotificationLog::query()->where('outage_id', $outage->id)->whereIn('recipient', ['noc-alerts@example.com', '+256700000001'])->count());
        // The person FIFO gave it to is told.
        $this->assertTrue(NotificationLog::query()->where('outage_id', $outage->id)->where('event', 'assigned')->where('recipient_user_id', $this->tier2->id)->exists());

        // And tickets stay out of the outage screens' data.
        $this->assertSame(0, Outage::query()->network()->count());
        $this->assertSame(0, Outage::query()->standard()->count());
        $this->assertSame(1, Outage::query()->tickets()->count());
    }

    public function test_ticket_reports_and_scheduled_emails_render(): void
    {
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH');
        $billing = $this->makeUser('Billing Agent', 'BILLING');
        app(Settings::class)->set(['ticketing.confirm_closure' => false]);

        $closed = $this->workflow->closeTicket($this->ticket('Wrong bill / double charge'), $billing, ['final_reason_id' => $this->reason('Billing query resolved')]);
        $this->ticket('Slow Speeds', ['account_number' => 'SF31']);
        $visit = $this->workflow->assignVisit($this->visit(), $dispatcher, ['technician_id' => $technician->id]);

        Carbon::setTestNow('2026-10-05 15:00:00');
        $snapshot = app(TicketMetrics::class)->snapshot(now(), 6);
        $this->assertSame(2, $snapshot['counts']['pending']);
        $this->assertSame(2, $snapshot['counts']['breached'], 'both are past the 3-hour target');
        $this->assertSame(1, $snapshot['counts']['closed']);
        $this->assertSame(100.0, $snapshot['counts']['closed_pct']);
        $this->assertSame(['CX Tier II', 'Site Visit Technician'], collect($snapshot['by_department']['rows'])->pluck('label')->sort()->values()->all());
        $this->assertSame('6 hours', app(TicketMetrics::class)->tier(300));

        $day = app(TicketMetrics::class)->period('day', now());
        $this->assertSame(['+ Inflow: tickets logged', 3, 2, 1], $day['movement'][1]);
        $this->assertSame(['Pending at the end (carried forward)', 2, 1, 1], $day['movement'][5]);

        foreach (array_keys(TicketReports::REPORTS) as $key) {
            $report = app(ReportRegistry::class)->run($key, ['from' => '2026-10-01', 'to' => '2026-10-31']);
            $this->assertIsArray($report->rowsArray(50), $key);
        }

        $register = app(ReportRegistry::class)->run('visit_register', [])->rowsArray(10);
        $this->assertSame($visit->ticket_number, $register[0]['ticket']);
        $this->assertSame($technician->name, $register[0]['technician']);
        $this->assertSame('Breached - still open', $register[0]['sla_status']);

        $service = app(ScheduledReportService::class);
        EmailTemplate::query()->where('code', 'ticket_pending_update')->update(['period_hours' => 6]);
        $pending = $service->render(EmailTemplate::query()->where('code', 'ticket_pending_update')->firstOrFail(), now());
        $this->assertStringContainsString('SLA PER DEPARTMENT', $pending['html']);
        $this->assertStringContainsString('CX Tier II', $pending['html']);
        $this->assertStringContainsString($closed->ticket_number, $pending['html']);
        $this->assertStringNotContainsString('{{', $pending['html']);

        foreach (['ticket_daily_report', 'ticket_weekly_report', 'ticket_monthly_report'] as $code) {
            $rendered = $service->render(EmailTemplate::query()->where('code', $code)->firstOrFail(), now());
            $this->assertStringContainsString('TICKET MOVEMENT', $rendered['html'], $code);
            $this->assertStringNotContainsString('{{', $rendered['html'], $code);
            $this->assertStringNotContainsString('{{', $rendered['subject'], $code);
        }

        // Sent with the pending list attached.
        $template = EmailTemplate::query()->where('code', 'ticket_pending_update')->firstOrFail();
        $template->update(['to' => ['cx-reports@example.com']]);
        $service->send($template, now());
        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail) => $mail->hasTo('cx-reports@example.com') && $mail->attachmentPath !== null);
    }
}
