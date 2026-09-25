<?php

namespace Tests\Feature;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\AvailabilityStatus;
use App\Enums\QueueEntryStatus;
use App\Enums\QueueType;
use App\Models\OutageAssignment;
use App\Models\OutageAssignmentHistory;
use App\Models\OutageQueueEntry;
use App\Models\UserLeave;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** FIFO acceptance criteria (spec §27.12). */
class FifoAssignmentTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    public function test_new_outage_is_queued_to_noc_and_assigned_to_an_eligible_user(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier I', 'NOC');

        $outage = $this->reportOutage($agent);

        $entry = OutageQueueEntry::query()->where('outage_id', $outage->id)->sole();
        $this->assertSame(QueueType::NocReview, $entry->queue_type);
        $this->assertSame(QueueEntryStatus::Assigned, $entry->status);

        $assignment = OutageAssignment::query()->where('outage_id', $outage->id)->sole();
        $this->assertSame($noc->id, $assignment->user_id);
        $this->assertSame(AssignmentType::AutoFifo, $assignment->assignment_type);
        $this->assertNotNull($assignment->ack_due_at);
        $this->assertSame(1, $assignment->decision_meta['eligible_count']);
        $this->assertSame($noc->id, $outage->refresh()->current_assignee_id);
    }

    public function test_ineligible_users_are_excluded(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $inactive = $this->makeUser('NOC Tier I', 'NOC', attributes: ['is_active' => false]);
        $offline = $this->makeUser('NOC Tier I', 'NOC', attributes: ['availability_status' => AvailabilityStatus::Offline]);
        $offShift = $this->makeUser('NOC Tier I', 'NOC', onShift: false);
        $onLeave = $this->makeUser('NOC Tier I', 'NOC');
        UserLeave::query()->create(['user_id' => $onLeave->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'status' => 'approved']);
        $wrongDepartment = $this->makeUser('NOC Tier I', 'INFRA');
        $noPermission = $this->makeUser('CX Agent', 'NOC');
        $eligible = $this->makeUser('NOC Tier I', 'NOC');

        $outage = $this->reportOutage($agent);

        $this->assertSame($eligible->id, $outage->refresh()->current_assignee_id);

        $rows = app(FifoAssignmentEngine::class)->explain($outage->queueEntries()->first());
        $byUser = $rows->keyBy(fn ($row) => $row['user']->id);

        $this->assertFalse($byUser[$inactive->id]['checks']['active']['passed']);
        $this->assertFalse($byUser[$offline->id]['checks']['availability']['passed']);
        $this->assertFalse($byUser[$offShift->id]['checks']['shift']['passed']);
        $this->assertFalse($byUser[$onLeave->id]['checks']['leave']['passed']);
        $this->assertFalse($byUser[$wrongDepartment->id]['checks']['department']['passed']);
        $this->assertArrayNotHasKey($noPermission->id, $byUser->all());
    }

    public function test_workload_limit_is_respected(): void
    {
        app(Settings::class)->set(['fifo.max_active_per_user' => 1, 'fifo.critical_bypass' => false]);
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier I', 'NOC');

        $first = $this->reportOutage($agent);
        $second = $this->reportOutage($agent);

        $this->assertSame($noc->id, $first->refresh()->current_assignee_id);
        $this->assertNull($second->refresh()->current_assignee_id);
        $this->assertSame(QueueEntryStatus::Pending, $second->queueEntries()->first()->status);
    }

    public function test_priority_aware_fifo_serves_critical_before_older_low_priority(): void
    {
        app(Settings::class)->set(['fifo.max_active_per_user' => 1, 'fifo.critical_bypass' => false]);
        $agent = $this->makeUser('CX Agent', 'CX');

        // Queue two tickets while nobody is available.
        $low = $this->reportOutage($agent, ['title' => 'Old low', 'customers_affected' => 2, 'start_time' => now()->subHour()]);
        $critical = $this->reportOutage($agent, ['title' => 'New critical', 'customers_affected' => 900]);

        $noc = $this->makeUser('NOC Tier I', 'NOC');
        app(FifoAssignmentEngine::class)->process(QueueType::NocReview);

        $this->assertSame($noc->id, $critical->refresh()->current_assignee_id, 'Critical ticket is served first');
        $this->assertNull($low->refresh()->current_assignee_id);

        // With priority-aware FIFO disabled, strict arrival order applies.
        OutageAssignment::query()->update(['status' => AssignmentStatus::Completed->value]);
        OutageQueueEntry::query()->update(['status' => QueueEntryStatus::Pending->value]);
        app(Settings::class)->set(['fifo.priority_aware' => false]);
        app(FifoAssignmentEngine::class)->process(QueueType::NocReview);

        $this->assertSame($noc->id, $low->refresh()->current_assignee_id, 'Oldest ticket first without priority awareness');
    }

    public function test_longest_waiting_user_receives_the_next_ticket(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $recent = $this->makeUser('NOC Tier I', 'NOC', attributes: ['last_assigned_at' => now()->subMinutes(5)]);
        $waitingLongest = $this->makeUser('NOC Tier I', 'NOC', attributes: ['last_assigned_at' => now()->subHours(3)]);

        $outage = $this->reportOutage($agent);

        $this->assertSame($waitingLongest->id, $outage->refresh()->current_assignee_id);
        $this->assertNotSame($recent->id, $outage->current_assignee_id);
    }

    public function test_rejection_requeues_to_next_eligible_user_and_is_audited(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $first = $this->makeUser('NOC Tier I', 'NOC', attributes: ['last_assigned_at' => null]);
        $second = $this->makeUser('NOC Tier I', 'NOC', attributes: ['last_assigned_at' => now()]);

        $outage = $this->reportOutage($agent);
        $assignment = OutageAssignment::query()->where('outage_id', $outage->id)->sole();
        $this->assertSame($first->id, $assignment->user_id);

        app(FifoAssignmentEngine::class)->reject($assignment, $first, 'Handling a P1 elsewhere');

        $this->assertSame(AssignmentStatus::Rejected, $assignment->refresh()->status);
        $this->assertSame($second->id, $outage->refresh()->current_assignee_id);
        $entry = $outage->queueEntries()->first();
        $this->assertTrue($entry->hasRejected($first->id));
        $this->assertSame(1, $entry->attempts);
        $this->assertDatabaseHas('outage_assignment_histories', ['outage_id' => $outage->id, 'action' => 'rejected', 'reason' => 'Handling a P1 elsewhere']);
    }

    public function test_acknowledgement_timeout_requeues(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $first = $this->makeUser('NOC Tier I', 'NOC', attributes: ['last_assigned_at' => null]);
        $second = $this->makeUser('NOC Tier I', 'NOC', attributes: ['last_assigned_at' => now()]);

        $outage = $this->reportOutage($agent);
        $this->travel(2)->hours();

        $this->assertSame(1, app(FifoAssignmentEngine::class)->processTimeouts());
        $this->assertSame($second->id, $outage->refresh()->current_assignee_id);
        $this->assertTrue(OutageAssignment::query()->where('user_id', $first->id)->where('status', AssignmentStatus::TimedOut->value)->exists());
    }

    public function test_manual_override_requires_permission_and_is_audited(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier I', 'NOC');
        $other = $this->makeUser('NOC Tier II', 'NOC', attributes: ['availability_status' => AvailabilityStatus::Offline]);
        $lead = $this->makeUser('NOC Team Leader', 'NOC');

        $outage = $this->reportOutage($agent);

        try {
            app(FifoAssignmentEngine::class)->manualAssign($outage, QueueType::NocReview, $other, $noc, 'no rights');
            $this->fail('Tier I must not override FIFO');
        } catch (AuthorizationException) {
        }

        $assignment = app(FifoAssignmentEngine::class)->manualAssign($outage, QueueType::NocReview, $other, $lead, 'Specialist needed');

        $this->assertSame(AssignmentType::Override, $assignment->assignment_type);
        $this->assertSame($lead->id, $assignment->assigned_by);
        $this->assertContains('Availability: Offline', $assignment->decision_meta['failed_checks']);
        $this->assertTrue(OutageAssignmentHistory::query()->where('outage_id', $outage->id)->where('action', 'override')->where('performed_by', $lead->id)->exists());
    }

    public function test_only_the_assignee_can_work_a_fifo_step(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $assignee = $this->makeUser('NOC Tier I', 'NOC', attributes: ['last_assigned_at' => null]);
        $bystander = $this->makeUser('NOC Tier I', 'NOC', attributes: ['last_assigned_at' => now()]);

        $outage = $this->reportOutage($agent);
        $this->assertSame($assignee->id, $outage->refresh()->current_assignee_id);

        $this->expectException(AuthorizationException::class);
        Auth::setUser($bystander);
        app(OutageWorkflow::class)->confirmByNoc($outage, $bystander);
    }

    public function test_dispatcher_can_only_pick_eligible_technicians(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier I', 'NOC');
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $crew = $this->makeTeam('Crew A', 'INFRA', $team);
        $otherCrew = $this->makeTeam('Crew B', 'INFRA', $team);
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $tech = $this->makeUser('Outage Technician', 'INFRA', [$crew]);
        $wrongCrewTech = $this->makeUser('Outage Technician', 'INFRA', [$otherCrew]);

        $outage = $this->reportOutage($agent);
        Auth::setUser($noc);
        app(OutageWorkflow::class)->confirmByNoc($outage, $noc);
        $this->assertSame($dispatcher->id, $outage->refresh()->current_assignee_id);

        Auth::setUser($dispatcher);
        try {
            app(OutageWorkflow::class)->dispatch($outage, ['team_id' => $team->id, 'sub_team_id' => $crew->id, 'technician_id' => $wrongCrewTech->id], $dispatcher);
            $this->fail('Technician outside the sub-team must be rejected');
        } catch (ValidationException) {
        }

        app(OutageWorkflow::class)->dispatch($outage, ['team_id' => $team->id, 'sub_team_id' => $crew->id, 'technician_id' => $tech->id], $dispatcher);

        $outage->refresh();
        $this->assertSame($tech->id, $outage->technician_id);
        $this->assertSame(AssignmentType::Dispatch, OutageAssignment::query()->where('user_id', $tech->id)->sole()->assignment_type);
    }

    public function test_user_becoming_unavailable_releases_unaccepted_tickets(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $first = $this->makeUser('NOC Tier I', 'NOC', attributes: ['last_assigned_at' => null]);
        $second = $this->makeUser('NOC Tier I', 'NOC', attributes: ['last_assigned_at' => now()]);

        $outage = $this->reportOutage($agent);
        app(FifoAssignmentEngine::class)->setAvailability($first, AvailabilityStatus::OnBreak, $first);

        $this->assertSame($second->id, $outage->refresh()->current_assignee_id);
        $this->assertDatabaseHas('user_availabilities', ['user_id' => $first->id, 'availability_status' => 'on_break']);
    }
}
