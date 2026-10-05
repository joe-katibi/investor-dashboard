<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Filament\Resources\Outages\OutageResource;
use App\Filament\Resources\Outages\Pages\ViewOutage;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Outages\OutageWorkflow;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Workflow additions:
 *  - NOC reject → CX Tier II callback / site visit
 *  - Support technician (drop cable → premises) on the same ticket, in parallel
 *  - System outages: IT first, escalate to NOC, IT confirms and shares feedback
 */
class CxSupportItFlowsTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        $this->workflow = app(OutageWorkflow::class);
    }

    private function inApp(User $user, string $event): bool
    {
        return NotificationLog::query()->where('channel', 'database')->where('recipient_user_id', $user->id)->where('event', $event)->exists();
    }

    // ------------------------------------------------------------------ NOC reject → CX Tier II

    public function test_noc_rejected_ticket_goes_to_cx_tier_two_who_record_the_callback_and_site_visit(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier I', 'NOC');
        $tier2 = $this->makeUser('CX Tier II', 'CX');
        $cxLead = $this->makeUser('CX Team Leader', 'CX', attributes: ['availability_status' => 'offline']);
        $dispatchLead = $this->makeUser('Dispatch Team Leader', 'DISPATCH', attributes: ['availability_status' => 'offline']);
        $outage = $this->reportOutage($agent);

        Auth::setUser($noc);
        $outage = $this->workflow->rejectByNoc($outage, $noc, 'Single customer, PON healthy');

        $this->assertTrue($outage->status->isCode(StatusCode::CxFollowUp));
        $this->assertSame(QueueType::CxFollowUp, $outage->current_queue);
        $this->assertSame($tier2->id, $outage->current_assignee_id, 'FIFO assigns the CX Tier II agent');
        $this->assertTrue($this->inApp($agent, 'noc_rejected'), 'the reporter is told NOC rejected it');

        Auth::setUser($tier2);

        // A site visit needs a date.
        try {
            $this->workflow->recordCxFollowUp($outage, $tier2, ['call_outcome' => 'reached_affected', 'callback_notes' => 'Still down', 'site_visit_required' => true]);
            $this->fail('A site visit without a date must be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('site_visit_at', $e->errors());
        }

        // First call: no answer, keep the ticket.
        $outage = $this->workflow->recordCxFollowUp($outage, $tier2, ['call_outcome' => 'no_answer', 'callback_notes' => 'Rang twice', 'close' => false]);
        $this->assertTrue($outage->status->isCode(StatusCode::CxFollowUp));
        $this->assertSame($tier2->id, $outage->current_assignee_id);

        // Second call: customer reached, site visit raised, ticket closed.
        $outage = $this->workflow->recordCxFollowUp($outage, $tier2, [
            'call_outcome' => 'reached_affected',
            'callback_notes' => 'LOS light on ONT; neighbours fine.',
            'site_visit_required' => true,
            'site_visit_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(),
            'site_visit_reference' => 'SV-1001',
            'site_visit_notes' => 'Check drop cable and ONT',
        ]);

        $this->assertTrue($outage->status->isCode(StatusCode::NocReject));
        $this->assertTrue($outage->is_closed);
        $this->assertSame(SlaState::NotApplicable, $outage->sla_state);
        $this->assertSame(0, $outage->openQueueEntries()->count());
        $this->assertSame(2, $outage->followUps()->count());
        $this->assertSame('SV-1001', $outage->followUps()->first()->site_visit_reference);
        $this->assertTrue($this->inApp($dispatchLead, 'site_visit_raised'), 'dispatch is told about the site visit');
        $this->assertTrue($this->inApp($cxLead, 'cx_follow_up'));
    }

    public function test_only_the_assigned_cx_agent_can_record_the_follow_up(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier I', 'NOC');
        $tier2 = $this->makeUser('CX Tier II', 'CX');
        $outage = $this->reportOutage($agent);
        Auth::setUser($noc);
        $outage = $this->workflow->rejectByNoc($outage, $noc, 'Not an outage');
        $other = $this->makeUser('CX Tier II', 'CX');

        $this->assertFalse($agent->can('cxFollowUp', $outage), 'CX Agents (Tier I) do not work the follow-up queue');

        $this->expectException(AuthorizationException::class);
        Auth::setUser($other);
        $this->workflow->recordCxFollowUp($outage, $other, ['call_outcome' => 'no_answer', 'callback_notes' => 'x']);
    }

    // ------------------------------------------------------------------ support technician, in parallel

    /** @return array{0: Outage, 1: User, 2: User, 3: User} outage, technician, support technician, infra lead */
    private function dispatchedOutage(): array
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $crew = $this->makeTeam('Crew', 'INFRA', $team);
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $tech = $this->makeUser('Outage Technician', 'INFRA', [$crew]);
        $lead = $this->makeUser('Infrastructure Team Leader', 'INFRA', [$team], ['availability_status' => 'offline']);
        $support = $this->makeUser('Support/Installation Technician', 'DISPATCH');

        $outage = $this->reportOutage($agent);
        Auth::setUser($noc);
        $outage = $this->workflow->confirmByNoc($outage, $noc);
        Auth::setUser($dispatcher);
        $outage = $this->workflow->dispatch($outage, ['team_id' => $team->id, 'sub_team_id' => $crew->id], $dispatcher);

        return [$outage, $tech, $support, $lead];
    }

    public function test_support_technician_works_the_same_ticket_in_parallel(): void
    {
        [$outage, $tech, $support] = $this->dispatchedOutage();
        $this->assertSame($tech->id, $outage->current_assignee_id);

        Auth::setUser($tech);
        $this->workflow->requestSupport($outage, $tech, ['work_required' => 'Replace drop cable to premises', 'customer_details' => 'Clinic, acc 100245']);
        $outage->refresh();

        // The outage technician keeps the ticket; the support technician gets a parallel assignment.
        $this->assertSame($tech->id, $outage->current_assignee_id);
        $this->assertSame(QueueType::Technician, $outage->current_queue);
        $this->assertTrue($outage->activeAssignments()->where('user_id', $support->id)->where('queue_type', QueueType::SupportTechnician->value)->exists());
        $this->assertTrue($this->inApp($support, 'assigned'));
        $this->assertFalse($tech->can('requestSupport', $outage), 'one open request at a time');

        // The live line cannot be reported restored while the drop cable work is open.
        try {
            $this->workflow->reportRestored($outage, $tech, 'Live line spliced');
            $this->fail('Restoration must wait for the support technician');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('support technician', $e->errors()['note'][0]);
        }

        // Only the assigned support technician (or an override holder) completes it.
        $this->assertFalse($tech->can('completeSupport', $outage));
        Auth::setUser($support);
        $outage = $this->workflow->completeSupport($outage, $support, 'Drop cable replaced, ONT re-terminated');

        $this->assertSame(SupportRequest::COMPLETED, $outage->supportRequests()->first()->status);
        $this->assertSame($tech->id, $outage->current_assignee_id, 'main ownership untouched');
        $this->assertTrue($this->inApp($tech, 'support_completed'), 'requester is told the support work is done');

        Auth::setUser($tech);
        $outage = $this->workflow->reportRestored($this->withSitePhotos($outage, $tech), $tech, 'Live line spliced; customer online');
        $this->assertTrue($outage->status->isCode(StatusCode::RestoreReported));
    }

    public function test_cancelling_the_support_request_unblocks_restoration(): void
    {
        [$outage, $tech, , $lead] = $this->dispatchedOutage();

        Auth::setUser($tech);
        $this->workflow->requestSupport($outage, $tech, ['work_required' => 'Check premises']);

        Auth::setUser($lead);
        $outage = $this->workflow->cancelSupport($outage, $lead, 'Premises side confirmed fine');
        $this->assertSame(SupportRequest::CANCELLED, $outage->supportRequests()->first()->status);
        $this->assertSame(0, $outage->openQueueEntries()->where('queue_type', QueueType::SupportTechnician->value)->count());
        $this->assertSame($tech->id, $outage->current_assignee_id);

        Auth::setUser($tech);
        $outage = $this->workflow->reportRestored($this->withSitePhotos($outage, $tech), $tech, 'Live line spliced');
        $this->assertTrue($outage->status->isCode(StatusCode::RestoreReported));
    }

    // ------------------------------------------------------------------ screens

    public function test_ticket_pages_offer_the_new_actions_and_forms_submit(): void
    {
        $admin = $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier I', 'NOC');
        $this->makeUser('CX Tier II', 'CX');
        $this->makeUser('IT Engineer', 'IT');

        $rejected = $this->reportOutage($agent);
        Auth::setUser($noc);
        $rejected = $this->workflow->rejectByNoc($rejected, $noc, 'Not an outage');
        $system = $this->reportSystemOutage($agent);
        [$dispatched, , $support] = $this->dispatchedOutage();

        $this->actingAs($admin);
        $this->get(OutageResource::getUrl('view', ['record' => $rejected]))->assertOk()->assertSee('Record customer callback')->assertSee('CX follow-up');
        $this->get(OutageResource::getUrl('view', ['record' => $system]))->assertOk()->assertSee('Escalate to NOC')->assertSee('Resolve (IT)');
        $this->get(OutageResource::getUrl('view', ['record' => $dispatched]))->assertOk()->assertSee('Request support technician');

        // Submit the CX form through the page (override holder, so FIFO ownership allows it).
        Livewire::test(ViewOutage::class, ['record' => $rejected->getRouteKey()])
            ->callAction(TestAction::make('cxFollowUp'), [
                'called_at' => now()->toDateTimeString(),
                'call_outcome' => 'reached_cpe_fault',
                'callback_notes' => 'Router power supply faulty',
                'site_visit_required' => true,
                'site_visit_at' => now()->addDay()->toDateTimeString(),
                'site_visit_reference' => 'SV-77',
                'site_visit_notes' => 'Replace PSU',
                'close' => true,
            ])
            ->assertHasNoFormErrors();
        $this->assertTrue($rejected->refresh()->is_closed);

        Livewire::test(ViewOutage::class, ['record' => $dispatched->getRouteKey()])
            ->callAction(TestAction::make('requestSupport'), ['work_required' => 'Drop cable', 'customer_details' => 'Acc 1', 'location' => 'Njewa'])
            ->assertHasNoFormErrors();
        $this->assertSame(1, $dispatched->supportRequests()->open()->count());
        Livewire::test(ViewOutage::class, ['record' => $dispatched->getRouteKey()])
            ->assertActionVisible(TestAction::make('reportRestored'))
            ->assertActionDisabled(TestAction::make('reportRestored'));
        $this->get(OutageResource::getUrl('view', ['record' => $dispatched]))->assertOk()->assertSee('Support work done')->assertSee($support->name);

        Livewire::test(ViewOutage::class, ['record' => $system->getRouteKey()])
            ->callAction(TestAction::make('itEscalateToNoc'), ['reason' => 'Core DNS'])
            ->assertHasNoFormErrors();
        $this->assertTrue($system->refresh()->status->isCode(StatusCode::EscalatedToNoc));
        $this->get(OutageResource::getUrl('view', ['record' => $system]))->assertOk()->assertSee('Resolved — return to IT');
    }

    // ------------------------------------------------------------------ system outages: IT first

    private function reportSystemOutage(User $agent): Outage
    {
        return $this->reportOutage($agent, [
            'title' => 'CRM down',
            'outage_type_id' => OutageType::query()->where('code', 'system')->value('id'),
            'customers_affected' => 0,
        ]);
    }

    public function test_system_outage_goes_to_it_escalates_to_noc_and_is_confirmed_by_it(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $it = $this->makeUser('IT Engineer', 'IT');
        $noc = $this->makeUser('NOC Tier II', 'NOC');

        $outage = $this->reportSystemOutage($agent);

        $this->assertTrue($outage->status->isCode(StatusCode::ItReview));
        $this->assertSame(QueueType::ItReview, $outage->current_queue);
        $this->assertSame($it->id, $outage->current_assignee_id, 'FIFO assigns an IT engineer, not NOC');
        $this->assertFalse($noc->can('confirm', $outage), 'NOC is not involved until IT escalates');

        Auth::setUser($it);
        $outage = $this->workflow->itEscalateToNoc($outage, $it, 'Core DNS failing');
        $this->assertTrue($outage->status->isCode(StatusCode::EscalatedToNoc));
        $this->assertSame($noc->id, $outage->current_assignee_id);
        $this->assertTrue($this->inApp($noc, 'assigned'));

        Auth::setUser($noc);
        $outage = $this->workflow->nocReturnToIt($outage, $noc, 'DNS resolver restarted');
        $this->assertTrue($outage->status->isCode(StatusCode::AwaitingItConfirmation));
        $this->assertSame(QueueType::ItConfirmation, $outage->current_queue);
        $this->assertSame($it->id, $outage->current_assignee_id);

        // IT checks: still failing → back to NOC, then fixed → returned again.
        Auth::setUser($it);
        $outage = $this->workflow->itReturnToNoc($outage, $it, 'Branch 3 still cannot log in');
        $this->assertTrue($outage->status->isCode(StatusCode::EscalatedToNoc));
        Auth::setUser($noc);
        $outage = $this->workflow->nocReturnToIt($outage, $noc, 'Branch 3 route fixed');

        Auth::setUser($it);
        $outage = $this->workflow->itResolve($outage, $it, 'DNS resolver failure on core; restarted and route fixed. CRM confirmed working at all branches.');

        $this->assertTrue($outage->status->isCode(StatusCode::Resolved));
        $this->assertTrue($outage->is_closed);
        $this->assertNotNull($outage->it_confirmed_at);
        $this->assertStringContainsString('CRM confirmed working', $outage->final_reason);
        $this->assertTrue($this->inApp($agent, 'resolved'), 'the reporter receives the IT feedback');
        $this->assertStringContainsString('CRM confirmed working', NotificationLog::query()->where('recipient_user_id', $agent->id)->where('event', 'resolved')->value('body'));
    }

    public function test_it_can_resolve_a_system_outage_directly(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $it = $this->makeUser('IT Engineer', 'IT');
        $outage = $this->reportSystemOutage($agent);

        Auth::setUser($it);

        try {
            $this->workflow->itResolve($outage, $it, ' ');
            $this->fail('Feedback is required');
        } catch (ValidationException) {
        }

        $outage = $this->workflow->itResolve($outage, $it, 'Dialer service restarted; agents back online.');

        $this->assertTrue($outage->status->isCode(StatusCode::Resolved));
        $this->assertNull($outage->it_escalated_to_noc_at);
        $this->assertSame(0, $outage->openQueueEntries()->count());
    }
}
