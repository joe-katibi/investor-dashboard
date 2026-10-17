<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\QueueType;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\SiteVisits\Pages\ViewSiteVisit;
use App\Filament\Resources\Tickets\TicketActions;
use App\Models\Outage;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Fifo\EligibilityEvaluator;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** Shifting / Relocation visits only go to the technicians allowed to do them; other visits to every technician. */
class ShiftingVisitTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private User $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
        $this->dispatcher = $this->makeUser('Shifting / Relocation Dispatcher', 'DISPATCH');
        Auth::setUser($this->dispatcher);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function visit(string $prefix, string $issue): Outage
    {
        return $this->workflow->createSiteVisit([
            'visit_type_id' => VisitType::query()->where('code_prefix', $prefix)->value('id'),
            'subcategory_id' => TicketSubcategory::query()->where('name', $issue)->value('id'),
            'account_number' => 'SF2659301', 'customer_name' => 'John Customer', 'calling_number' => '0772000010',
            'address' => 'Lukuli water pump',
        ], $this->dispatcher);
    }

    public function test_shifting_visits_are_kept_for_shifting_technicians(): void
    {
        $this->assertSame(VisitType::SHIFTING_PERMISSION, VisitType::query()->where('code_prefix', 'SHI')->value('technician_permission'));
        $this->assertNull(VisitType::query()->where('code_prefix', 'SUP')->value('technician_permission'));

        $plain = $this->makeUser('Site Visit Technician', 'DISPATCH');
        $shifting = $this->makeUser('Shifting / Relocation Technician', 'DISPATCH');
        $visit = $this->visit('SHI', 'Shifting');
        $this->assertSame($this->dispatcher->id, $visit->current_assignee_id);

        // Only the shifting technician is offered, and a plain technician named anyway is refused.
        $options = TicketActions::visitTechnicians($visit);
        $this->assertArrayHasKey($shifting->id, $options);
        $this->assertArrayNotHasKey($plain->id, $options);

        try {
            $this->workflow->assignVisit($visit, $this->dispatcher, ['technician_id' => $plain->id]);
            $this->fail('A technician without the shifting permission was given a shifting visit.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('technician_id', $e->errors());
        }

        $visit = $this->workflow->assignVisit($visit, $this->dispatcher, ['technician_id' => $shifting->id]);
        $this->assertSame(QueueType::VisitTechnician, $visit->current_queue);
        $this->assertSame($shifting->id, $visit->current_assignee_id);
    }

    public function test_the_technician_queue_offers_a_shifting_visit_only_to_shifting_technicians(): void
    {
        $plain = $this->makeUser('Site Visit Technician', 'DISPATCH');

        // Automatic: with no shifting technician on duty the visit waits — it does not go to the plain one.
        $visit = $this->workflow->assignVisit($this->visit('SHI', 'Shifting'), $this->dispatcher, []);
        $this->assertSame(QueueType::VisitTechnician, $visit->current_queue);
        $this->assertNull($visit->current_assignee_id);

        // A manager cannot override it to them either.
        $manager = $this->makeUser('Super Admin', 'MGMT');
        try {
            app(FifoAssignmentEngine::class)->manualAssign($visit, QueueType::VisitTechnician, $plain, $manager, 'Nobody else today');
            $this->fail('A shifting visit was overridden to a technician without the shifting permission.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user_id', $e->errors());
        }
        // (The Super Admin holds every permission, shifting included: off duty, so the queue skips them.)
        $manager->forceFill(['availability_status' => AvailabilityStatus::Offline])->save();

        // A shifting technician comes on duty: they get it.
        $shifting = $this->makeUser('Shifting / Relocation Technician', 'DISPATCH');
        app(FifoAssignmentEngine::class)->process(QueueType::VisitTechnician);
        $this->assertSame($shifting->id, $visit->refresh()->current_assignee_id);

        // Any other visit still goes to every technician.
        $support = $this->workflow->assignVisit($this->visit('SUP', 'LOS Flashing Red'), $this->dispatcher, ['technician_id' => $plain->id]);
        $this->assertSame($plain->id, $support->current_assignee_id);
    }

    public function test_shifting_visits_are_dispatched_only_by_shifting_dispatchers(): void
    {
        $this->assertSame(VisitType::SHIFTING_DISPATCH_PERMISSION, VisitType::query()->where('code_prefix', 'SHI')->value('dispatcher_permission'));
        $this->assertNull(VisitType::query()->where('code_prefix', 'SUP')->value('dispatcher_permission'));

        $plain = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $shiftingTech = $this->makeUser('Shifting / Relocation Technician', 'DISPATCH');
        $visit = $this->visit('SHI', 'Shifting');
        $support = $this->visit('SUP', 'LOS Flashing Red');

        // The dispatch queue gives it to the shifting dispatcher; a plain dispatcher may not act on it, other visits yes.
        $this->assertSame($this->dispatcher->id, $visit->current_assignee_id);
        $this->assertFalse($plain->can('visitAssign', $visit));
        $this->assertTrue($plain->can('visitAssign', $support));
        $this->assertTrue($this->dispatcher->can('visitAssign', $visit));

        // Not listed as a dispatcher for it on a hand-over.
        $options = OutageActions::queueUserOptions(QueueType::VisitDispatch, EligibilityEvaluator::visitPermission($visit, QueueType::VisitDispatch));
        $this->assertArrayHasKey($this->dispatcher->id, $options);
        $this->assertArrayNotHasKey($plain->id, $options);

        // A plain dispatcher booking one straight to a technician is stopped with an explanation.
        try {
            $this->workflow->createSiteVisit([
                'visit_type_id' => VisitType::query()->where('code_prefix', 'SHI')->value('id'),
                'subcategory_id' => TicketSubcategory::query()->where('name', 'Shifting')->value('id'),
                'account_number' => 'SF2659302', 'customer_name' => 'Jane Customer', 'calling_number' => '0772000011',
                'address' => 'Kansanga', 'technician_id' => $shiftingTech->id,
            ], $plain);
            $this->fail('A plain dispatcher dispatched a shifting visit.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Dispatch Shifting / Relocation visits', $e->errors()['technician_id'][0]);
        }

        $visit = $this->workflow->assignVisit($visit, $this->dispatcher, ['technician_id' => $shiftingTech->id]);
        $this->assertSame($shiftingTech->id, $visit->current_assignee_id);
    }

    public function test_a_manager_may_give_a_shifting_visit_to_any_dispatcher(): void
    {
        $plain = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $shiftingTech = $this->makeUser('Shifting / Relocation Technician', 'DISPATCH');
        $visit = $this->visit('SHI', 'Shifting');
        $this->actingAs($this->makeUser('Ticketing Manager', 'MGMT'));

        // The queue offers the Shifting dispatchers and every dispatcher; "Assign to" lists them accordingly.
        $page = Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])
            ->mountAction(TestAction::make('reassign'))
            ->assertSchemaStateSet(['queue_type' => 'visit_dispatch:kept'])
            ->assertFormFieldExists('queue_type', fn (Select $field) => array_keys($field->getOptions()) === ['visit_dispatch:kept', 'visit_dispatch'])
            ->assertFormFieldExists('user_id', fn (Select $field) => ! array_key_exists($plain->id, $field->getOptions()) && array_key_exists($this->dispatcher->id, $field->getOptions()));

        $page->fillForm(['queue_type' => 'visit_dispatch'])
            ->assertFormFieldExists('user_id', fn (Select $field) => array_key_exists($plain->id, $field->getOptions()));

        $page->fillForm(['user_id' => $plain->id, 'reason' => 'Shifting dispatcher on leave'])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame($plain->id, $visit->refresh()->current_assignee_id);

        // The dispatcher it was given to may now dispatch it.
        $this->assertTrue($plain->can('visitAssign', $visit));
        $visit = $this->workflow->assignVisit($visit, $plain, ['technician_id' => $shiftingTech->id]);
        $this->assertSame($shiftingTech->id, $visit->current_assignee_id);
    }

    public function test_overriding_fifo_on_customer_tickets_is_a_permission_of_its_own(): void
    {
        $outageLead = $this->makeUser($this->role('Outage Lead', ['outage.view', 'ticket.view', 'queue.view', 'queue.override']), 'MGMT');
        $ticketLead = $this->makeUser($this->role('Ticket Lead', ['outage.view', 'ticket.view', 'queue.view', 'ticket.override']), 'MGMT');
        $other = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $visit = $this->visit('SUP', 'LOS Flashing Red');
        $outage = $this->reportOutage($this->makeUser('NOC Tier I', 'NOC'));

        $this->assertFalse($outageLead->can('overrideAssignment', $visit));
        $this->assertTrue($outageLead->can('overrideAssignment', $outage));
        $this->assertTrue($ticketLead->can('overrideAssignment', $visit));
        $this->assertFalse($ticketLead->can('overrideAssignment', $outage));

        try {
            app(FifoAssignmentEngine::class)->manualAssign($visit, QueueType::VisitDispatch, $other, $outageLead, 'Cover');
            $this->fail('Overriding FIFO on outages also overrode a site visit.');
        } catch (AuthorizationException) {
        }

        app(FifoAssignmentEngine::class)->manualAssign($visit, QueueType::VisitDispatch, $other, $ticketLead, 'Cover');
        $this->assertSame($other->id, $visit->refresh()->current_assignee_id);

        // And may act on a visit held by someone else.
        app(FifoAssignmentEngine::class)->authorizeWork($visit, QueueType::VisitDispatch, $ticketLead);
        $this->expectException(AuthorizationException::class);
        app(FifoAssignmentEngine::class)->authorizeWork($visit, QueueType::VisitDispatch, $outageLead);
    }

    /** @param  list<string>  $permissions */
    private function role(string $name, array $permissions): string
    {
        Role::findOrCreate($name, 'web')->givePermissionTo($permissions);

        return $name;
    }

    public function test_the_visit_type_screen_sets_which_technicians_do_it(): void
    {
        $this->actingAs($this->makeUser('Super Admin', 'MGMT'));

        $this->get('/admin/customer-care/visit-types')->assertOk()
            ->assertSee('Shifting / Relocation technicians only')
            ->assertSee('Shifting / Relocation dispatchers only');
    }
}
