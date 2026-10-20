<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Enums\QueueType;
use App\Models\FinalReason;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Models\TicketCategory;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * A completed visit goes back to the dispatcher who dispatched it; "Inhouse Shifting" is a Support case;
 * My Work shows every ticket that is with someone.
 */
class DispatchDesksTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
    }

    private function supportVisit(User $by, string $account): Outage
    {
        Auth::setUser($by);

        return $this->workflow->createSiteVisit([
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'),
            'subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => $account, 'customer_name' => $account, 'calling_number' => '0772000010', 'address' => 'Kansanga',
        ], $by);
    }

    private function complete(Outage $visit, User $technician): Outage
    {
        Storage::disk('local')->put('outage-attachments/geo.jpg', 'image');
        Storage::disk('local')->put('outage-attachments/speed.jpg', 'image');
        $visit = $this->workflow->visitArrive($visit, $technician);

        return $this->workflow->completeVisit($visit, $technician, [
            'final_reason_id' => FinalReason::query()->value('id'), 'resolution_notes' => 'Fixed', 'fat_number' => 'FAT-1',
            'geo_photo' => 'outage-attachments/geo.jpg', 'speed_photo' => 'outage-attachments/speed.jpg',
        ]);
    }

    public function test_a_completed_visit_goes_back_to_the_dispatcher_who_dispatched_it(): void
    {
        $first = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $second = $this->makeUser('Site Visit Dispatcher', 'DISPATCH', attributes: ['availability_status' => 'offline']);
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH');

        // The second dispatcher dispatches it (they took it over); the first is the one FIFO would pick next.
        $visit = $this->supportVisit($first, 'SF1');
        $second->forceFill(['availability_status' => AvailabilityStatus::Available])->save();
        app(FifoAssignmentEngine::class)->manualAssign($visit, QueueType::VisitDispatch, $second, $this->makeUser('Ticketing Manager', 'MGMT'), 'Cover');
        $visit = $this->workflow->assignVisit($visit->refresh(), $second, ['technician_id' => $technician->id]);

        $visit = $this->complete($visit, $technician);
        $this->assertSame(QueueType::VisitDispatch, $visit->current_queue);
        $this->assertSame($second->id, $visit->current_assignee_id, 'back with the dispatcher who dispatched it');

        // Offline when it comes back: still theirs, accepted, and not taken away by the availability sweep.
        $again = $this->workflow->assignVisit($this->supportVisit($first, 'SF2'), $first, ['technician_id' => $technician->id]);
        $first->forceFill(['availability_status' => AvailabilityStatus::Offline])->save();
        $again = $this->complete($again, $technician);
        $this->assertSame($first->id, $again->current_assignee_id);
        app(FifoAssignmentEngine::class)->sweepIneligibleAssignees();
        $this->assertSame($first->id, $again->refresh()->current_assignee_id);
        $this->assertSame(AssignmentStatus::Accepted, OutageAssignment::query()->where('outage_id', $again->id)->where('user_id', $first->id)->latest('id')->value('status'));

        // On approved leave: whoever is on duty takes it.
        $first->forceFill(['availability_status' => AvailabilityStatus::Available])->save();
        $second->forceFill(['availability_status' => AvailabilityStatus::Offline])->save();
        $third = $this->workflow->assignVisit($this->supportVisit($first, 'SF5'), $first, ['technician_id' => $technician->id]);
        $second->forceFill(['availability_status' => AvailabilityStatus::Available])->save();
        $first->leaves()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addDays(3), 'leave_type' => 'annual', 'status' => 'approved']);
        $third = $this->complete($third, $technician);
        $this->assertSame($second->id, $third->current_assignee_id);
    }

    public function test_inhouse_shifting_is_a_support_case(): void
    {
        $inhouse = TicketSubcategory::query()->where('name', 'Inhouse Shifting')->firstOrFail();

        $this->assertSame('TECH', TicketCategory::query()->whereKey($inhouse->ticket_category_id)->value('code'));
        $this->assertSame('SUP', VisitType::query()->whereKey($inhouse->visit_type_id)->value('code_prefix'));
    }

    public function test_a_ticket_with_someone_is_put_back_in_their_my_work(): void
    {
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $visit = $this->supportVisit($dispatcher, 'SF3');
        $this->assertSame($dispatcher->id, $visit->current_assignee_id);

        // As after the server move: "with" the dispatcher, but no assignment of theirs.
        OutageAssignment::query()->where('outage_id', $visit->id)->delete();
        $engine = app(FifoAssignmentEngine::class);

        $found = $engine->repairMyWork(false);
        $this->assertCount(1, $found['missing']);
        $this->assertSame(0, OutageAssignment::query()->where('outage_id', $visit->id)->count(), 'the check alone changes nothing');

        $this->artisan('oms:check-my-work', ['--fix' => true])->expectsOutputToContain('Repaired')->assertSuccessful();
        $assignment = OutageAssignment::query()->where('outage_id', $visit->id)->where('user_id', $dispatcher->id)->firstOrFail();
        $this->assertSame(AssignmentStatus::Accepted, $assignment->status);

        $this->artisan('oms:check-my-work')->expectsOutputToContain('Every ticket that is with someone is in their My Work')->assertSuccessful();
    }
}
