<?php

namespace Tests\Feature;

use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Filament\Pages\MyWork;
use App\Filament\Resources\CustomFlows\CustomFlowResource;
use App\Filament\Resources\CustomFlows\Pages\CreateCustomFlow;
use App\Filament\Resources\CustomFlows\Pages\EditCustomFlow;
use App\Filament\Resources\CustomTickets\CustomTicketResource;
use App\Filament\Resources\CustomTickets\Pages\CreateCustomTicket;
use App\Filament\Resources\CustomTickets\Pages\ViewCustomTicket;
use App\Filament\Support\OutageLink;
use App\Models\AuditLog;
use App\Models\CustomFlow;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\Team;
use App\Models\User;
use App\Services\Outages\OutageWorkflow;
use App\Services\Reports\ReportRegistry;
use App\Services\Sla\DeskSla;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Workflow configuration, stage 2: a flow is built from the screen — steps in order, each for a
 * team — and tickets are raised and worked through it. Editing a flow never touches the tickets
 * already on their way.
 */
class CustomWorkflowTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private Team $procurement;

    private Team $finance;

    private User $raiser;

    private User $buyer;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->seedReference();
        Mail::fake();
        $this->workflow = app(OutageWorkflow::class);

        $this->procurement = $this->makeTeam('Procurement', 'MGMT');
        $this->finance = $this->makeTeam('Finance', 'BILLING');
        Role::findByName('CX Agent')->givePermissionTo('custom.raise');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->raiser = $this->makeUser('CX Agent', 'CX');
        // The people of a step are simply the members of its team — whatever their role.
        $this->buyer = $this->makeUser('IT Engineer', 'IT', [$this->procurement]);
        $this->accountant = $this->makeUser('Billing Agent', 'BILLING', [$this->finance]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function flow(array $attributes = [], ?array $steps = null): CustomFlow
    {
        $flow = CustomFlow::query()->create($attributes + ['name' => 'Purchase request', 'prefix' => 'pur', 'sla_minutes' => 480]);

        foreach ($steps ?? [
            ['name' => 'Procurement check', 'team_id' => $this->procurement->id, 'target_minutes' => 30, 'instructions' => 'Check the quotation.'],
            ['name' => 'Finance approval', 'team_id' => $this->finance->id, 'target_minutes' => 60, 'note_required' => true],
            ['name' => 'Order placed', 'team_id' => $this->procurement->id],
        ] as $i => $step) {
            $flow->steps()->create($step + ['position' => $i]);
        }

        return $flow->refresh();
    }

    private function raise(CustomFlow $flow, string $title = 'Ten fibre splicers'): Outage
    {
        Auth::setUser($this->raiser);

        return $this->workflow->raiseCustomTicket($flow, ['title' => $title, 'description' => 'For the Kira team'], $this->raiser);
    }

    public function test_a_ticket_goes_through_the_steps_of_its_flow_team_by_team(): void
    {
        $flow = $this->flow();
        $ticket = $this->raise($flow);

        $this->assertSame('PUR-000001', $ticket->ticket_number);
        $this->assertTrue($ticket->isCustomFlow());
        $this->assertTrue($ticket->status->isCode(StatusCode::CustomOpen));
        $this->assertSame('Step 1 of 3: Procurement check', $ticket->customTicket->progressLabel());
        $this->assertSame($this->buyer->id, $ticket->current_assignee_id, 'the oldest ticket goes to a member of the step\'s team');
        $this->assertSame(480, (int) $ticket->start_time->diffInMinutes($ticket->sla_due_at));

        $entry = $ticket->queueEntries()->sole();
        $this->assertSame(['Procurement check', $this->procurement->id, 30, null], [$entry->label(), $entry->team_id, $entry->sla_minutes, $entry->department_id]);
        $this->assertTrue(NotificationLog::query()->where('outage_id', $ticket->id)->where('event', 'assigned')->where('recipient_user_id', $this->buyer->id)->exists());

        // Someone outside the step cannot act on it — not even a manager's permission for other tickets helps.
        Auth::setUser($this->accountant);
        $this->assertFalse($this->accountant->can('workCustomStep', $ticket));
        try {
            $this->workflow->completeCustomStep($ticket, $this->accountant, 'me first');
            $this->fail('Only the person holding the ticket completes the step');
        } catch (AuthorizationException) {
        }

        // Step 1 → step 2.
        $this->travel(10)->minutes();
        Auth::setUser($this->buyer);
        $ticket = $this->workflow->completeCustomStep($ticket, $this->buyer, 'Quotation checked: 4.2M');
        $this->assertSame('Step 2 of 3: Finance approval', $ticket->customTicket->progressLabel());
        $this->assertSame($this->accountant->id, $ticket->current_assignee_id);
        $this->assertSame(60, $ticket->currentQueueEntry->sla_minutes);

        // Step 2 needs a note; then it sends the ticket back, and procurement returns it.
        Auth::setUser($this->accountant);
        try {
            $this->workflow->completeCustomStep($ticket, $this->accountant);
            $this->fail('This step needs a note');
        } catch (ValidationException) {
        }

        $ticket = $this->workflow->sendBackCustomStep($ticket, $this->accountant, 'Second quotation missing');
        $this->assertSame(['Step 1 of 3: Procurement check', 1], [$ticket->customTicket->progressLabel(), $ticket->customTicket->sent_back_count]);
        $this->assertSame($this->buyer->id, $ticket->current_assignee_id);

        Auth::setUser($this->buyer);
        $ticket = $this->workflow->completeCustomStep($ticket, $this->buyer, 'Second quotation attached');
        Auth::setUser($this->accountant);
        $ticket = $this->workflow->completeCustomStep($ticket, $this->accountant, 'Approved from the tools budget');

        // Last step: a closing note, and the ticket is completed within its SLA.
        Auth::setUser($this->buyer);
        $this->assertSame('Step 3 of 3: Order placed', $ticket->customTicket->progressLabel());
        try {
            $this->workflow->completeCustomStep($ticket, $this->buyer);
            $this->fail('The last step needs a closing note');
        } catch (ValidationException) {
        }

        $this->travel(30)->minutes();
        $ticket = $this->workflow->completeCustomStep($ticket, $this->buyer, 'Ordered from Fibre Tools Ltd, LPO 4471');

        $this->assertTrue($ticket->is_closed);
        $this->assertTrue($ticket->status->isCode(StatusCode::CustomClosed));
        $this->assertSame(SlaState::Met, $ticket->sla_state);
        $this->assertSame('Ordered from Fibre Tools Ltd, LPO 4471', $ticket->final_reason);
        $this->assertNull($ticket->current_assignee_id);
        $this->assertSame(5, $ticket->queueEntries()->count(), 'every stay at a step is kept: 1, 2, back to 1, 2, 3');
        $this->assertTrue(NotificationLog::query()->where('outage_id', $ticket->id)->where('event', 'resolved')->where('recipient_user_id', $this->raiser->id)->exists(), 'the raiser hears how it ended');

        // The audit trail tells the story.
        $this->assertSame(
            ['Workflow: Raised', 'Workflow: Step completed', 'Workflow: Sent back', 'Workflow: Step completed', 'Workflow: Step completed', 'Workflow: Step completed', 'Workflow: Completed'],
            AuditLog::query()->where('event', 'like', 'wf_%')->orderBy('id')->get()->map->eventLabel()->all(),
        );
    }

    public function test_editing_a_flow_never_changes_tickets_already_on_their_way(): void
    {
        $flow = $this->flow();
        $old = $this->raise($flow, 'Raised before the change');
        $raisedUnder = $flow->version;
        $this->assertSame($raisedUnder, $old->customTicket->flow_version);

        // The administrator reorders, renames, removes a step, adds one and changes the team.
        $legal = $this->makeTeam('Legal', 'MGMT');
        $lawyer = $this->makeUser('IT Engineer', 'IT', [$legal]);
        $steps = $flow->steps;
        $steps[1]->update(['position' => 0, 'name' => 'Budget check']);
        $steps[0]->update(['position' => 1]);
        $steps[2]->delete();
        $flow->steps()->create(['name' => 'Legal review', 'team_id' => $legal->id, 'position' => 2]);
        $flow->refresh();
        $this->assertGreaterThan($raisedUnder, $flow->version, 'every change of the steps is a new version');

        $new = $this->raise($flow, 'Raised after the change');
        $this->assertSame(['Budget check', 'Procurement check', 'Legal review'], array_column($new->customTicket->steps, 'name'));
        $this->assertSame($this->accountant->id, $new->current_assignee_id, 'the new first step is Finance');
        $this->assertSame($flow->version, $new->customTicket->flow_version);

        // The earlier ticket still follows the steps it was raised with, to the end.
        $this->assertSame(['Procurement check', 'Finance approval', 'Order placed'], array_column($old->refresh()->customTicket->steps, 'name'));
        Auth::setUser($this->buyer);
        $old = $this->workflow->completeCustomStep($old, $this->buyer, 'Checked');
        $this->assertSame('Step 2 of 3: Finance approval', $old->customTicket->progressLabel());

        // Switching the flow off stops new tickets only.
        $flow->update(['is_active' => false]);
        try {
            $this->raise($flow->refresh());
            $this->fail('A flow that is switched off takes no new tickets');
        } catch (ValidationException) {
        }

        Auth::setUser($this->accountant);
        $this->assertSame('Step 2 of 3: Procurement check', $this->workflow->completeCustomStep($new, $this->accountant, 'Budget is there')->customTicket->progressLabel());
        $this->assertNotNull($lawyer);
    }

    public function test_step_targets_manual_steps_cancelling_and_who_sees_what(): void
    {
        $manager = $this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']);
        $outsider = $this->makeUser('Billing Agent', 'BILLING');
        $flow = $this->flow(['prefix' => 'REQ', 'raise_roles' => ['CX Agent']], [
            ['name' => 'Triage', 'team_id' => $this->procurement->id, 'assignment_mode' => 'manual', 'target_minutes' => 20],
            ['name' => 'Done by finance', 'team_id' => $this->finance->id],
        ]);

        // Only the roles named on the flow may raise in it.
        Role::findByName('Billing Agent')->givePermissionTo('custom.raise');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        try {
            $this->workflow->raiseCustomTicket($flow, ['title' => 'Not for me'], $outsider->refresh());
            $this->fail('The flow is for CX agents');
        } catch (ValidationException) {
        }

        // A manual step waits for a manager, who gives it to someone of the team.
        $ticket = $this->raise($flow);
        $this->assertNull($ticket->current_assignee_id);
        $this->assertTrue($ticket->queueEntries()->sole()->auto_assign_blocked);

        // Who sees it: the raiser, the team of the step and people with "custom.view" — nobody else.
        $this->assertTrue($this->raiser->can('view', $ticket));
        $this->assertTrue($this->buyer->can('view', $ticket));
        $this->assertTrue($manager->can('view', $ticket));
        $this->assertFalse($outsider->can('view', $ticket));
        $this->assertFalse($this->accountant->can('view', $ticket), 'the team of a later step does not see it yet');
        // None of the outage, 4G or customer ticket actions apply to it.
        foreach (['confirm', 'dispatch', 'reportRestored', 'resolve', 'changeStatus', 'ticketClose', 'update', 'delete'] as $ability) {
            $this->assertFalse($this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline'])->can($ability, $ticket), $ability);
        }

        // The step's own target: warned, then breached, like any desk.
        $this->travel(16)->minutes();
        $this->assertSame(1, app(DeskSla::class)->check()['warned']);
        $this->travel(5)->minutes();
        $this->assertSame(1, app(DeskSla::class)->check()['breached']);
        $breach = NotificationLog::query()->where('outage_id', $ticket->id)->where('event', 'desk_sla_breach')->get();
        $this->assertStringContainsString('Triage', $breach->first()->subject);
        $this->assertContains($manager->id, $breach->pluck('recipient_user_id')->all(), 'stakeholders who may view custom tickets');

        $this->actingAs($manager);
        Livewire::test(ViewCustomTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('Triage')->assertSee('here now')
            ->callAction('customAssign', data: ['user_id' => $this->buyer->id, 'reason' => 'Buyer on duty'])
            ->assertHasNoActionErrors();
        $this->assertSame($this->buyer->id, $ticket->refresh()->current_assignee_id);

        // The raiser may cancel only while nobody holds it; someone with "custom.cancel" always.
        $this->assertFalse($this->raiser->can('cancelCustom', $ticket));
        $ticket = $this->workflow->cancelCustomTicket($ticket, $manager, 'Bought locally instead');
        $this->assertTrue($ticket->status->isCode(StatusCode::CustomCancelled));
        $this->assertSame(SlaState::NotApplicable, $ticket->sla_state);
        $this->assertSame(0, $ticket->openQueueEntries()->count());
    }

    public function test_the_flow_is_built_on_the_screen_and_tickets_are_raised_and_worked_there(): void
    {
        $admin = $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);

        // Building needs "Workflow configuration".
        $this->actingAs($this->raiser);
        $this->get(CustomFlowResource::getUrl('index'))->assertForbidden();

        $this->actingAs($admin);
        Livewire::test(CreateCustomFlow::class)
            ->fillForm([
                'name' => 'Vehicle request', 'prefix' => 'veh', 'sla_minutes' => 120,
                'steps' => [
                    ['name' => 'Fleet check', 'team_id' => $this->procurement->id, 'assignment_mode' => 'auto', 'target_minutes' => 15, 'can_send_back' => true, 'note_required' => false],
                    ['name' => 'Manager approval', 'team_id' => $this->finance->id, 'assignment_mode' => 'auto', 'can_send_back' => true, 'note_required' => false],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $flow = CustomFlow::query()->where('prefix', 'VEH')->sole();
        $this->assertSame(['Fleet check', 'Manager approval'], $flow->steps->pluck('name')->all());
        $this->assertSame([], $flow->problems());

        // Reordering on the edit screen is a new version.
        $version = $flow->version;
        Livewire::test(EditCustomFlow::class, ['record' => $flow->getRouteKey()])->assertOk()->assertSee('Fleet check');
        $flow->steps[0]->update(['position' => 5]);
        $this->assertSame(['Manager approval', 'Fleet check'], $flow->refresh()->steps->pluck('name')->all());
        $this->assertGreaterThan($version, $flow->version);
        $this->get(CustomFlowResource::getUrl('index'))->assertOk()->assertSee('Manager approval → Fleet check');

        // Raising from the screen, by someone who may.
        $this->actingAs($this->raiser);
        $this->assertSame([$flow->id => 'Vehicle request'], CustomTicketResource::flowsFor($this->raiser));
        Livewire::test(CreateCustomTicket::class)
            ->fillForm(['custom_flow_id' => $flow->id, 'title' => 'Pick-up for Mukono', 'description' => 'Two days'])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = Outage::query()->customFlows()->sole();
        $this->assertSame('VEH-000001', $ticket->ticket_number);
        $this->assertSame($this->accountant->id, $ticket->current_assignee_id);
        $this->assertSame(CustomTicketResource::getUrl('view', ['record' => $ticket->id]), OutageLink::url($ticket));
        $this->get(CustomTicketResource::getUrl('index'))->assertOk()->assertSee('Pick-up for Mukono')->assertSee('Manager approval');

        // The person holding it sees it in My Work under the step's name and completes it from the ticket page.
        $this->actingAs($this->accountant);
        Livewire::test(MyWork::class)->assertSee(str_replace('-', '‑', $ticket->ticket_number))->assertSee('Manager approval');
        Livewire::test(ViewCustomTicket::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('Step 1 of 2')
            ->callAction('customComplete', data: ['note' => 'Approved'])
            ->assertHasNoActionErrors();
        $this->assertSame('Step 2 of 2: Fleet check', $ticket->refresh()->customTicket->progressLabel());
        $this->assertSame($this->buyer->id, $ticket->current_assignee_id);

        // …and it is in the life-cycle and desk reports under "Custom workflows", with the step names.
        $this->actingAs($admin);
        $steps = collect(app(ReportRegistry::class)->run('ticket_lifecycle', ['modules' => ['custom']])->rowsArray());
        $this->assertSame(['Raised', 'Manager approval', 'Fleet check'], $steps->pluck('stage')->all());
        $this->assertSame(['Custom workflows'], $steps->pluck('module')->unique()->values()->all());
        $this->assertNotContains($ticket->ticket_number, collect(app(ReportRegistry::class)->run('outage_register')->rowsArray())->pluck('ticket')->all(), 'it is not a network outage');
    }
}
