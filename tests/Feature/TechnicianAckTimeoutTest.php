<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\QueueType;
use App\Models\OutageAssignment;
use App\Models\QueueDefinition;
use App\Models\SlaPolicy;
use App\Models\TicketSubcategory;
use App\Models\VisitType;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Site visit and Shifting / Relocation technicians have the acceptance time of FIFO Settings, whatever the queue or SLA
 * policy says; a visit the dispatcher gave them stays theirs while they are offline.
 */
class TechnicianAckTimeoutTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_visit_technicians_get_the_fifo_settings_timeout(): void
    {
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->seedReference();
        Mail::fake();
        $workflow = app(OutageWorkflow::class);
        app(Settings::class)->set(['fifo.ack_timeout_minutes' => 45]);

        // A queue and an SLA policy with 5 minutes of their own.
        QueueDefinition::query()->where('queue_type', QueueType::VisitTechnician->value)->update(['ack_timeout_minutes' => 5]);
        SlaPolicy::query()->update(['ack_timeout_minutes' => 5]);

        $admin = $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH');
        Auth::setUser($frontline);

        $ticket = $workflow->createTicket(['subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => 'SF1', 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001', 'description' => 'No light'], $frontline);
        $visit = $workflow->raiseSiteVisit($ticket, $admin, ['visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id')]);
        $workflow->assignVisit($visit, $dispatcher, ['technician_id' => $technician->id]);

        $assignment = OutageAssignment::query()->where('outage_id', $visit->id)->where('queue_type', QueueType::VisitTechnician->value)->latest('id')->firstOrFail();
        $this->assertSame($technician->id, $assignment->user_id);
        $this->assertEquals(now()->addMinutes(45), $assignment->ack_due_at);

        // Given by the dispatcher: kept when the technician is (or goes) offline — not swept to the next one.
        $other = $this->makeUser('Site Visit Technician', 'DISPATCH');
        $engine = app(FifoAssignmentEngine::class);
        $engine->setAvailability($technician, AvailabilityStatus::Offline, $technician, 'Phone off');
        $engine->sweepIneligibleAssignees();
        $this->assertTrue($assignment->refresh()->isActive());
        $this->assertSame($technician->id, $visit->refresh()->current_assignee_id);
        $this->assertNotSame($other->id, $visit->current_assignee_id);
    }
}
