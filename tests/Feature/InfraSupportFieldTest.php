<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Models\Outage;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Mobile\MobileActions;
use App\Services\Outages\OutageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * An Infrastructure Support technician in the mobile app: accept, on the way, start at the customer premise, pause /
 * resume, then back to the Infrastructure Support dispatcher — for a site visit and for a customer ticket escalated there.
 */
class InfraSupportFieldTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private User $infraDispatcher;

    private User $infraTech;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
        $this->infraDispatcher = $this->makeUser('Infra Support Dispatcher', 'INFRA');
        $this->infraTech = $this->makeUser('Infra Support Technician', 'INFRA');
    }

    /** @return list<string> the action keys the app offers the Infrastructure Support technician */
    private function appActions(Outage $outage): array
    {
        return array_column(app(MobileActions::class)->for($this->infraTech, $outage->refresh()), 'key');
    }

    /** From the Infrastructure Support dispatcher to the technician, and the trip in the app. */
    private function fieldTrip(Outage $outage): Outage
    {
        // The dispatcher assigns the technician in the app, by name (another one is free and next in line).
        $this->makeUser('Infra Support Technician', 'INFRA');
        $dispatcherActions = app(MobileActions::class)->for($this->infraDispatcher, $outage->refresh());
        $assign = collect($dispatcherActions)->firstWhere('key', 'infra_assign');
        $this->assertNotNull($assign, 'Assign technician in the app');
        $this->assertContains((string) $this->infraTech->id, array_column(collect($assign['fields'])->firstWhere('name', 'technician_id')['options'], 'value'));
        app(MobileActions::class)->run($this->infraDispatcher, $outage, 'infra_assign',
            Request::create('/', 'POST', ['technician_id' => (string) $this->infraTech->id, 'note' => 'Fix the drop cable at the pole']));
        $outage->refresh();
        $this->assertSame(QueueType::InfraSupportTechnician, $outage->current_queue);
        $this->assertSame($this->infraTech->id, $outage->current_assignee_id);
        $this->assertContains('accept', $this->appActions($outage));

        app(FifoAssignmentEngine::class)->accept($outage->currentAssignment, $this->infraTech);
        $actions = $this->appActions($outage);
        $this->assertContains('visit_en_route', $actions, 'on the way');
        $this->assertContains('visit_arrive', $actions, 'start at customer premise');

        $outage = $this->workflow->visitEnRoute($outage, $this->infraTech);
        $outage = $this->workflow->visitArrive($outage, $this->infraTech);
        $this->assertContains('visit_pause', $this->appActions($outage));
        $outage = $this->workflow->visitPause($outage, $this->infraTech, 'Waiting for the ladder');
        $this->assertContains('visit_resume', $this->appActions($outage));
        $outage = $this->workflow->visitResume($outage, $this->infraTech);

        // Done: back to the Infrastructure Support dispatcher who sent it.
        $outage = $this->workflow->returnTicket($outage, $this->infraTech, 'Drop cable replaced, light levels good');
        $this->assertSame(QueueType::InfraSupportDispatch, $outage->current_queue);
        $this->assertSame($this->infraDispatcher->id, $outage->current_assignee_id);

        return $outage;
    }

    public function test_a_site_visit_escalated_to_infrastructure_support(): void
    {
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH');
        Auth::setUser($dispatcher);
        $visit = $this->workflow->createSiteVisit([
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'),
            'subcategory_id' => TicketSubcategory::query()->where('name', 'Drop Cable Cut')->value('id'),
            'account_number' => 'SF1', 'customer_name' => 'SF1', 'calling_number' => '0772000010', 'address' => 'Lukuli',
        ], $dispatcher);

        // The site technician got there first and found the cable cut: back to the dispatcher, who escalates.
        $visit = $this->workflow->assignVisit($visit, $dispatcher, ['technician_id' => $technician->id]);
        $visit = $this->workflow->visitArrive($visit, $technician);
        $visit = $this->workflow->visitNotCompleted($visit, $technician, 'Drop cable cut at the pole');
        $visit = $this->workflow->routeTicket($visit, $dispatcher, QueueType::InfraSupportDispatch, 'Drop cable cut at the pole');

        // The Infrastructure Support technician makes their own trip (the earlier arrival does not count).
        $this->fieldTrip($visit);
    }

    public function test_a_customer_ticket_escalated_to_infrastructure_support_by_the_noc(): void
    {
        $agent = $this->makeUser('CX Frontline', 'CX');
        $tier2 = $this->makeUser('CX Tier II', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        Auth::setUser($agent);
        $ticket = $this->workflow->createTicket(['subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => 'SF2', 'calling_number' => '0772000001', 'description' => 'No light'], $agent);
        $ticket = $this->workflow->routeTicket($ticket->refresh(), $tier2, QueueType::TicketNoc, 'No light at all');
        $ticket = $this->workflow->routeTicket($ticket, $noc, QueueType::InfraSupportDispatch, 'Fibre cut on the drop');

        $this->fieldTrip($ticket);
    }
}
