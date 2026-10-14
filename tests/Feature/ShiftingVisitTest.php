<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\QueueType;
use App\Filament\Resources\Tickets\TicketActions;
use App\Models\Outage;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
        $this->dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
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

    public function test_the_visit_type_screen_sets_which_technicians_do_it(): void
    {
        $this->actingAs($this->makeUser('Super Admin', 'MGMT'));

        $this->get('/admin/customer-care/visit-types')->assertOk()->assertSee('Shifting / Relocation technicians only');
    }
}
