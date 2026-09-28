<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Models\Fdt;
use App\Models\NotificationLog;
use App\Models\OutageFlagEvent;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\Vertical;
use App\Services\Outages\OutageWorkflow;
use App\Services\Sla\SlaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** Phase 1 acceptance criteria (spec §24). */
class OutageWorkflowTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    public function test_ticket_numbers_follow_type_prefixes_and_sequences(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');

        $a = $this->reportOutage($agent);
        $b = $this->reportOutage($agent);
        $c = $this->reportOutage($agent, ['outage_type_id' => OutageType::query()->where('code', 'emergency')->value('id')]);
        // Planned maintenance is raised by Infrastructure through its own request flow.
        $infra = $this->makeUser('Infrastructure Team Leader', 'INFRA', attributes: ['availability_status' => 'offline']);
        $d = app(OutageWorkflow::class)->requestMaintenance([
            'title' => 'OLT upgrade', 'vertical_id' => Vertical::query()->where('code', Vertical::FTTH)->value('id'),
            'scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addHours(4), 'work_description' => 'Firmware upgrade',
        ], $infra);

        $this->assertSame('OUT-00000000001', $a->ticket_number);
        $this->assertSame('OUT-00000000002', $b->ticket_number);
        $this->assertSame('EMO-00000000001', $c->ticket_number);
        $this->assertSame('PLM-00000000001', $d->ticket_number);
    }

    public function test_new_outage_starts_unconfirmed_with_rule_driven_priority_and_sla(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');

        $outage = $this->reportOutage($agent, ['customers_affected' => 250]);

        $this->assertTrue($outage->status->isCode(StatusCode::SupportUnconfirmed));
        $this->assertSame(Priority::CRITICAL, $outage->priority->code);
        $this->assertNotNull($outage->noc_escalated_at);
        $this->assertNotNull($outage->sla_due_at);
        $this->assertSame(240, (int) $outage->start_time->diffInMinutes($outage->sla_due_at));
    }

    public function test_updating_impact_recalculates_priority_and_requeues_order(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $editor = $this->makeUser('NOC Tier II', 'NOC', attributes: ['availability_status' => 'offline']);
        $outage = $this->reportOutage($agent, ['customers_affected' => 5]);
        $this->assertSame('low', $outage->priority->code);

        Auth::setUser($editor);
        $outage = app(OutageWorkflow::class)->update($outage, ['customers_affected' => 75], $editor);

        $this->assertSame('high', $outage->priority->code);
        $this->assertSame(Priority::query()->where('code', 'high')->value('queue_rank'), $outage->queueEntries()->first()->priority_rank);
    }

    public function test_noc_reject_requires_a_reason_and_returns_the_ticket_to_cx(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier I', 'NOC');
        $outage = $this->reportOutage($agent);
        Auth::setUser($noc);

        try {
            app(OutageWorkflow::class)->rejectByNoc($outage, $noc, '  ');
            $this->fail('Empty reason must be rejected');
        } catch (ValidationException) {
        }

        $outage = app(OutageWorkflow::class)->rejectByNoc($outage, $noc, 'Customer CPE fault, not an outage');

        // Not closed yet: CX Tier II calls the customer back first (see CxSupportItFlowsTest).
        $this->assertTrue($outage->status->isCode(StatusCode::CxFollowUp));
        $this->assertFalse($outage->is_closed);
        $this->assertNotNull($outage->sla_paused_at, 'SLA paused while it is not a confirmed outage');
        $this->assertSame(QueueType::CxFollowUp, $outage->current_queue);
        $this->assertSame('Customer CPE fault, not an outage', $outage->rejection_reason);
    }

    public function test_full_lifecycle_from_report_to_resolution(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $crew = $this->makeTeam('Crew', 'INFRA', $team);
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $tech = $this->makeUser('Outage Technician', 'INFRA', [$crew]);
        $workflow = app(OutageWorkflow::class);

        $outage = $this->reportOutage($agent);

        // NOC confirm → automatic Infrastructure escalation + FIFO assignment
        Auth::setUser($noc);
        $outage = $workflow->confirmByNoc($outage, $noc, 'Alarm confirmed');
        $this->assertTrue($outage->status->isCode(StatusCode::AssignedToInfrastructure));
        $this->assertNotNull($outage->infra_escalated_at);
        $this->assertSame($dispatcher->id, $outage->current_assignee_id);

        // Dispatch with automatic technician FIFO
        Auth::setUser($dispatcher);
        $outage = $workflow->dispatch($outage, ['team_id' => $team->id, 'sub_team_id' => $crew->id], $dispatcher);
        $this->assertTrue($outage->status->isCode(StatusCode::TeamDispatched));
        $this->assertSame($tech->id, $outage->current_assignee_id);
        $this->assertSame(QueueType::Technician, $outage->current_queue);

        // Technician works and reports restoration → NOC restore queue
        Auth::setUser($tech);
        $workflow->addUpdate($outage, $tech, 'On site');
        $outage = $workflow->reportRestored($outage, $tech, 'Spliced');
        $this->assertTrue($outage->status->isCode(StatusCode::RestoreReported));
        $this->assertSame($noc->id, $outage->current_assignee_id);

        Auth::setUser($noc);
        $outage = $workflow->confirmRestoration($outage, $noc);
        $this->assertNotNull($outage->restore_confirmed_at);

        // Final reason is mandatory for the resolved status
        try {
            $workflow->resolve($outage, $noc, null);
            $this->fail('Resolve without final reason must fail');
        } catch (ValidationException) {
        }

        $outage = $workflow->resolve($outage, $noc, 'Fibre cut by contractor');
        $this->assertTrue($outage->is_closed);
        $this->assertSame(SlaState::Met, $outage->sla_state);
        $this->assertSame('Fibre cut by contractor', $outage->final_reason);
        // Reported → NOC confirmed → Infrastructure → Dispatched → Restore reported → Restore confirmed → Resolved
        $this->assertSame(7, $outage->statusHistories()->count());
    }

    public function test_permissions_block_unauthorised_transitions(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->makeUser('NOC Tier I', 'NOC');
        $outage = $this->reportOutage($agent);

        $this->expectException(AuthorizationException::class);
        Auth::setUser($agent);
        app(OutageWorkflow::class)->confirmByNoc($outage, $agent);
    }

    public function test_super_admin_still_respects_workflow_state(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $admin = $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);
        $outage = $this->reportOutage($agent);

        $this->assertTrue($admin->can('confirm', $outage));
        $this->assertFalse($admin->can('resolve', $outage), 'Cannot resolve before restoration is confirmed');
        $this->assertFalse($admin->can('dispatch', $outage), 'Cannot dispatch before NOC confirmation');
    }

    public function test_topology_selection_keeps_only_consistent_active_children(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $oltA = $this->makeOlt('A');
        $oltB = $this->makeOlt('B');
        $fdtA = Fdt::query()->create(['olt_id' => $oltA->id, 'name' => 'F1', 'is_active' => true]);
        $fdtB = Fdt::query()->create(['olt_id' => $oltB->id, 'name' => 'F2', 'is_active' => true]);
        $inactive = Fdt::query()->create(['olt_id' => $oltA->id, 'name' => 'F3', 'is_active' => false]);

        $outage = $this->reportOutage($agent, ['olt_ids' => [$oltA->id], 'fdt_ids' => [$fdtA->id, $fdtB->id, $inactive->id]]);

        $this->assertSame([$oltA->id], $outage->olts()->pluck('olts.id')->all());
        $this->assertSame([$fdtA->id], $outage->fdts()->pluck('fdts.id')->all());
    }

    public function test_sms_and_credit_are_explicit_flags_with_audit(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $outage = $this->reportOutage($agent);

        Auth::setUser($agent);
        app(OutageWorkflow::class)->setFlag($outage, 'sms_sent', true, $agent);

        $outage->refresh();
        $this->assertTrue($outage->sms_sent);
        $this->assertSame($agent->id, $outage->sms_updated_by);
        $this->assertNotNull($outage->sms_updated_at);
        $this->assertTrue(OutageFlagEvent::query()->where('outage_id', $outage->id)->where('flag', 'sms_sent')->where('new_value', true)->exists());
    }

    public function test_status_requiring_final_reason_cannot_be_set_without_it(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $tech = $this->makeUser('Outage Technician', 'INFRA');
        $outage = $this->reportOutage($agent);
        $status = OutageStatus::forCode(StatusCode::AwaitingFieldAccess);

        Auth::setUser($tech);
        $this->expectException(ValidationException::class);
        app(OutageWorkflow::class)->changeStatus($outage, $status, $tech, null);
    }

    public function test_sla_pre_breach_and_breach_are_notified_once(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->makeUser('NOC Team Leader', 'NOC');
        $outage = $this->reportOutage($agent, ['customers_affected' => 500, 'start_time' => now()]);

        $this->travel(200)->minutes(); // 4h SLA, 60 min pre-breach window
        app(SlaService::class)->check();
        $this->assertSame(SlaState::AtRisk, $outage->refresh()->sla_state);
        $this->assertNotNull($outage->sla_pre_breach_notified_at);

        $this->travel(60)->minutes();
        app(SlaService::class)->check();
        app(SlaService::class)->check();

        $this->assertSame(SlaState::Breached, $outage->refresh()->sla_state);
        $this->assertSame(1, $outage->slaEvents()->where('event', 'breached')->count());
        $this->assertTrue(NotificationLog::query()->where('outage_id', $outage->id)->where('event', 'sla_breach')->exists());
    }
}
