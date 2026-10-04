<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Filament\Resources\MobilityOutages\MobilityActions;
use App\Mail\VendorNotificationMail;
use App\Models\AuditLog;
use App\Models\Enodeb;
use App\Models\ImpactRule;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\OutageUpdate;
use App\Models\Priority;
use App\Models\QueueDefinition;
use App\Models\TransmissionNode;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTemplate;
use App\Models\Vertical;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Mobility\MobilityImpact;
use App\Services\Mobility\VendorEscalationMonitor;
use App\Services\Mobility\VendorNotifier;
use App\Services\Outages\OutageWorkflow;
use App\Services\Outages\PriorityResolver;
use App\Services\Topology\MobilityTopologyImporter;
use App\Support\Settings;
use Database\Seeders\MobilityTopologySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * 4G NOC module: topology, severity rules per vertical, the internal (NOC → engineer → NOC) and
 * vendor (NOC → vendor → NOC) workflows, vendor e-mail audit trail, reopen / cancel, escalation
 * of silent vendors and the separation of 4G notifications from FTTH.
 */
class MobilityNocTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private User $noc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        $this->workflow = app(OutageWorkflow::class);
        $this->noc = $this->makeUser('4G NOC', 'MOBILITY', attributes: ['email' => 'noc4g@example.com']);
        Auth::setUser($this->noc);
    }

    private function type(string $code): int
    {
        return OutageType::query()->where('code', $code)->value('id');
    }

    private function site(string $id, string $name, ?Vendor $vendor = null): Enodeb
    {
        return Enodeb::query()->create(['site_id' => $id, 'name' => $name, 'tower_company' => $vendor?->code, 'vendor_id' => $vendor?->id, 'is_active' => true]);
    }

    private function vendor(string $code = 'ATC', ?string $email = 'ops@atc.example'): Vendor
    {
        $vendor = Vendor::query()->where('code', $code)->firstOrFail();
        $vendor->update(['email' => $email, 'cc_emails' => $email ? ['lead@atc.example'] : [], 'contact_person' => 'ATC NOC']);

        return $vendor;
    }

    private function ticket(string $type, array $overrides = []): Outage
    {
        return $this->workflow->createMobility(array_merge([
            'outage_type_id' => $this->type($type),
            'title' => 'Site down',
            'start_time' => now()->subMinutes(10),
            'detected_at' => now()->subMinutes(5),
        ], $overrides), $this->noc);
    }

    private function code(Outage $outage): ?string
    {
        return $outage->refresh()->status->code;
    }

    // ------------------------------------------------------------------ topology

    public function test_bundled_workbook_seeds_sites_and_the_transmission_hierarchy(): void
    {
        $this->seed(MobilityTopologySeeder::class);

        $this->assertSame(354, Enodeb::query()->count());
        $this->assertSame(668, TransmissionNode::query()->ofType(TransmissionNode::SSG)->count());

        $site = Enodeb::query()->where('site_id', '4624')->firstOrFail();
        $this->assertSame('Nsambya Kevina', $site->name);
        $this->assertSame('ATC', $site->tower_company);
        $this->assertSame(Vendor::query()->where('code', 'ATC')->value('id'), $site->vendor_id, 'sites link to their tower company vendor');

        // ssg-1111 → CSG 111 → ASG 11 → RSG 1
        $ssg = TransmissionNode::query()->where('code', 'SSG-1111')->with('parent.parent.parent')->firstOrFail();
        $this->assertSame($site->id, $ssg->enodeb_id);
        $this->assertTrue($ssg->is_active);
        $this->assertSame('CSG-111', $ssg->parent->code);
        $this->assertSame('Nsambya OLT csg-111 - Nsambya Kevina', $ssg->parent->name);
        $this->assertSame('10.255.224.14', $ssg->parent->gateway_ip);
        $this->assertSame('ASG-11', $ssg->parent->parent->code);
        $this->assertSame('RSG-1', $ssg->parent->parent->parent->code);

        $this->assertFalse(TransmissionNode::query()->where('code', 'SSG-1115')->value('is_active'), 'SSGs without a site are not selectable');
        $this->assertSame(0, TransmissionNode::query()->where('status_note', 'like', '%psw%')->count(), 'device logins in the notes column are never imported');

        // Re-running changes nothing.
        $sites = app(MobilityTopologyImporter::class)->import(MobilityTopologySeeder::bundledPaths()[0]);
        $nodes = app(MobilityTopologyImporter::class)->import(MobilityTopologySeeder::bundledPaths()[1]);
        $this->assertSame([0, 0], [$sites['sites']['created'], $sites['sites']['updated']]);
        $this->assertSame([0, 0], [$nodes['nodes']['created'], $nodes['nodes']['updated']]);
    }

    // ------------------------------------------------------------------ severity per vertical

    public function test_4g_severity_follows_the_impact_rules_and_ftth_still_rates_by_customers(): void
    {
        $vertical = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');
        $severity = fn (string $type, array $counts) => app(PriorityResolver::class)->forImpact($vertical, $this->type($type), $counts)['priority']->code;

        // eNodeB faults are always Minor.
        $this->assertSame(Priority::MOBILITY_MINOR, $severity('mobility_enodeb', ['cells' => 4]));
        $this->assertSame(Priority::MOBILITY_MINOR, $severity('mobility_enodeb', ['enodebs' => 1]));

        // Transmission: 1–3 eNodeBs Minor, 3–6 Major (overlap resolves upwards), >6 Critical; 1–2 SSGs Major, ≥3 Critical; any ASG / RSG Critical.
        $this->assertSame(Priority::MOBILITY_MINOR, $severity('mobility_transmission', ['enodebs' => 2]));
        $this->assertSame(Priority::MOBILITY_MAJOR, $severity('mobility_transmission', ['enodebs' => 3]));
        $this->assertSame(Priority::MOBILITY_CRITICAL, $severity('mobility_transmission', ['enodebs' => 7]));
        $this->assertSame(Priority::MOBILITY_MAJOR, $severity('mobility_transmission', ['enodebs' => 1, 'ssg' => 2]));
        $this->assertSame(Priority::MOBILITY_CRITICAL, $severity('mobility_transmission', ['ssg' => 3]));
        $this->assertSame(Priority::MOBILITY_CRITICAL, $severity('mobility_transmission', ['asg' => 1]));

        // Power: 2 SSGs is already Critical.
        $this->assertSame(Priority::MOBILITY_MAJOR, $severity('mobility_power', ['ssg' => 1]));
        $this->assertSame(Priority::MOBILITY_CRITICAL, $severity('mobility_power', ['ssg' => 2]));
        $this->assertSame(Priority::MOBILITY_MINOR, $severity('mobility_power', []), 'no rule matches → the 4G default');

        // Rules are configuration: tighten one and the result follows.
        ImpactRule::query()->where('name', 'eNodeB fault – eNodeB down')->update(['priority_id' => Priority::query()->where('code', Priority::MOBILITY_MAJOR)->value('id')]);
        PriorityResolver::flush();
        $this->assertSame(Priority::MOBILITY_MAJOR, $severity('mobility_enodeb', ['enodebs' => 1]));

        // FTTH is untouched: customers decide, and never a 4G level.
        $ftth = Vertical::query()->where('code', Vertical::FTTH)->value('id');
        $this->assertSame('critical', app(PriorityResolver::class)->forCustomers(500, $ftth)->code);
        $this->assertSame('low', app(PriorityResolver::class)->forCustomers(3, $ftth)->code);
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->assertSame('medium', $this->reportOutage($agent)->priority->code);
    }

    public function test_a_transmission_node_takes_everything_below_it_down(): void
    {
        $siteA = $this->site('100', 'Alpha');
        $siteB = $this->site('200', 'Bravo');
        $extra = $this->site('300', 'Charlie');
        $node = fn (string $type, string $code, ?TransmissionNode $parent = null, ?Enodeb $site = null, bool $active = true) => TransmissionNode::query()
            ->create(['type' => $type, 'code' => $code, 'name' => $code, 'parent_id' => $parent?->id, 'enodeb_id' => $site?->id, 'is_active' => $active]);

        $asg = $node('ASG', 'ASG-91');
        $csg = $node('CSG', 'CSG-911', $asg);
        $node('SSG', 'SSG-9111', $csg, $siteA);
        $node('SSG', 'SSG-9112', $csg, $siteB);
        $node('SSG', 'SSG-9113', $csg, null, false); // not deployed

        $counts = app(MobilityImpact::class)->counts([$extra->id], [$csg->id], 2);
        $this->assertSame(['enodebs' => 3, 'cells' => 2, 'ssg' => 2, 'csg' => 1, 'asg' => 0, 'rsg' => 0, 'customers' => 0], $counts);

        $ticket = $this->ticket('mobility_transmission', ['node_ids' => [$asg->id], 'transmission_type' => 'fibre', 'transmission_link_id' => 'LNK-9']);
        $this->assertSame(['enodebs' => 2, 'ssg' => 2, 'csg' => 1, 'asg' => 1], array_intersect_key($ticket->impact_counts, array_flip(['enodebs', 'ssg', 'csg', 'asg'])));
        $this->assertSame(Priority::MOBILITY_CRITICAL, $ticket->priority->code);
        $this->assertSame(2, $ticket->sites_down);
        $this->assertSame('transmission', $ticket->fault_domain);
    }

    // ------------------------------------------------------------------ internal workflow

    public function test_enodeb_ticket_goes_noc_engineer_noc_and_only_the_noc_closes(): void
    {
        // eNodeB outages are worked by the 4G technicians ($engineer / $other: the people working this ticket).
        $engineer = $this->makeUser('4G Technician', 'MOBILITY');
        $other = $this->makeUser('4G Technician', 'MOBILITY');
        $site = $this->site('4624', 'Nsambya Kevina');

        // Nobody picked: FIFO gives it to the technician who has waited longest.
        $ticket = $this->ticket('mobility_enodeb', ['enodeb_ids' => [$site->id], 'cells_down' => 3]);

        $this->assertSame('4G-ENB-000001', $ticket->ticket_number);
        $this->assertSame(StatusCode::MobilityAssigned->value, $ticket->status->code);
        $this->assertSame($engineer->id, $ticket->current_assignee_id);
        $this->assertSame(['New', 'Assigned'], $ticket->statusHistories()->with('toStatus')->reorder('id')->get()->pluck('toStatus.name')->all());
        $this->assertTrue(OutageUpdate::query()->where('outage_id', $ticket->id)->where('actor_type', 'System')->where('body', 'like', "Assigned to {$engineer->name} automatically by FIFO%")->exists());
        $this->assertSame(OutageType::WORKFLOW_MOBILITY_INTERNAL, $ticket->workflow);
        $this->assertSame(Priority::MOBILITY_MINOR, $ticket->priority->code);
        $this->assertSame('4G Minor – 24h', $ticket->slaPolicy->name);
        $this->assertNotNull($ticket->detected_at);

        // The FTTH form / flow never takes a 4G type.
        try {
            $this->workflow->create(['title' => 'x', 'outage_type_id' => $this->type('mobility_enodeb'), 'vertical_id' => $ticket->vertical_id], $this->makeUser('NOC Manager', 'NOC'));
            $this->fail('4G types must go through the 4G flow');
        } catch (ValidationException) {
        }

        // The engineer cannot take it: FIFO passes it to the next one, and the ticket follows.
        Auth::setUser($engineer);
        app(FifoAssignmentEngine::class)->reject($ticket->currentAssignment, $engineer, 'On another site');
        $ticket->refresh();
        $this->assertSame($other->id, $ticket->current_assignee_id);
        $this->assertSame($other->id, $ticket->technician_id);
        $this->assertSame(StatusCode::MobilityAssigned->value, $ticket->status->code);

        // The NOC can still pick a person by hand.
        Auth::setUser($this->noc);
        $ticket = $this->workflow->assignMobilityEngineer($ticket, $this->noc, $engineer->id, 'Please check the RRUs');
        $this->assertSame(StatusCode::MobilityAssigned->value, $ticket->status->code);
        $this->assertSame($engineer->id, $ticket->current_assignee_id);
        $this->assertSame($engineer->id, $ticket->technician_id);
        $this->assertSame(QueueType::MobilityEngineer, $ticket->current_queue);
        $this->assertNotNull($ticket->acknowledged_at);

        // Someone else's ticket: not theirs to work.
        Auth::setUser($other);
        try {
            $this->workflow->startMobilityWork($ticket, $other);
            $this->fail('only the assigned engineer starts the work');
        } catch (AuthorizationException) {
        }

        Auth::setUser($engineer);
        $ticket = $this->workflow->startMobilityWork($ticket, $engineer);
        $this->assertSame(StatusCode::MobilityInProgress->value, $ticket->status->code);
        $this->assertNotNull($ticket->work_started_at);

        $this->workflow->addUpdate($ticket, $engineer, 'RRU 2 alarm, replacing the unit', 'progress');
        $ticket = $this->workflow->setMobilityStatus($ticket, StatusCode::MobilityMonitoring, $engineer, 'Unit replaced, watching KPIs');
        $this->assertSame(StatusCode::MobilityMonitoring->value, $ticket->status->code);

        // The engineer cannot close — not before, not after resolving.
        $this->assertFalse($engineer->can('mobilityClose', $ticket));

        try {
            $this->workflow->markMobilityResolved($ticket, $engineer, ['root_cause' => '', 'resolution' => 'Replaced']);
            $this->fail('root cause is mandatory');
        } catch (ValidationException) {
        }

        $ticket = $this->workflow->markMobilityResolved($ticket, $engineer, ['root_cause' => 'Faulty RRU', 'resolution' => 'RRU replaced']);
        $this->assertSame(StatusCode::MobilityResolvedPendingNoc->value, $ticket->status->code);
        $this->assertFalse($ticket->is_closed, 'resolved by the engineer is not closed');
        $this->assertSame(QueueType::MobilityNoc, $ticket->current_queue);
        $this->assertNotNull($ticket->restored_at);

        try {
            $this->workflow->closeMobility($ticket, $engineer);
            $this->fail('engineers cannot close');
        } catch (AuthorizationException) {
        }

        // NOC checks: still down → back to the same engineer.
        Auth::setUser($this->noc);
        $ticket = $this->workflow->rejectMobilityResolution($ticket, $this->noc, 'Cell 2 still off air');
        $this->assertSame(StatusCode::MobilityInProgress->value, $ticket->status->code);
        $this->assertSame($engineer->id, $ticket->current_assignee_id);
        $this->assertNull($ticket->restored_at);

        Auth::setUser($engineer);
        $ticket = $this->workflow->markMobilityResolved($ticket, $engineer, ['root_cause' => 'Faulty RRU and jumper', 'resolution' => 'Jumper replaced']);

        Auth::setUser($this->noc);
        $ticket = $this->workflow->closeMobility($ticket, $this->noc, ['note' => 'All cells on air']);
        $this->assertSame(StatusCode::MobilityClosed->value, $ticket->status->code);
        $this->assertTrue($ticket->is_closed);
        $this->assertSame(SlaState::Met, $ticket->sla_state);
        $this->assertSame($this->noc->id, $ticket->resolved_by);
        $this->assertStringContainsString('Faulty RRU and jumper', $ticket->final_reason);
        $this->assertNull($ticket->current_queue);

        // The timeline says who did what.
        $actors = OutageUpdate::query()->where('outage_id', $ticket->id)->pluck('actor_type')->unique()->values()->all();
        $this->assertEqualsCanonicalizing([OutageUpdate::ACTOR_NOC, OutageUpdate::ACTOR_TECHNICIAN, OutageUpdate::ACTOR_SYSTEM], $actors);
        $this->assertTrue(OutageUpdate::query()->where('outage_id', $ticket->id)->where('body', 'like', 'Assigned to%')->exists());
    }

    public function test_transmission_outages_go_to_engineers_and_enodeb_outages_to_technicians(): void
    {
        $fifo = app(FifoAssignmentEngine::class);
        // The engineer has waited longest: without the rule, FIFO would hand them the eNodeB ticket.
        $engineer = $this->makeUser('4G Engineer', 'MOBILITY');
        $technician = $this->makeUser('4G Technician', 'MOBILITY');

        $enodeb = $this->ticket('mobility_enodeb', ['enodeb_ids' => [$this->site('4624', 'Nsambya Kevina')->id]]);
        $transmission = $this->ticket('mobility_transmission', ['transmission_type' => 'fibre', 'transmission_link_id' => 'LNK-9']);

        $this->assertSame($technician->id, $enodeb->current_assignee_id, 'eNodeB outage → 4G technician');
        $this->assertSame($engineer->id, $transmission->current_assignee_id, 'transmission outage → 4G engineer');
        $this->assertTrue(OutageUpdate::query()->where('outage_id', $enodeb->id)->where('body', 'like', '%next available 4G technician is assigned automatically.')->exists());
        $this->assertTrue(OutageUpdate::query()->where('outage_id', $transmission->id)->where('body', 'like', '%next available 4G engineer is assigned automatically.')->exists());

        // The FIFO explanation names the rule.
        $check = fn (Outage $ticket, User $user) => $fifo->explain($ticket->openQueueEntries()->where('queue_type', QueueType::MobilityEngineer->value)->first())
            ->first(fn (array $row) => $row['user']->id === $user->id)['checks']['fault_domain'];
        $this->assertFalse($check($enodeb, $engineer)['passed']);
        $this->assertSame('Handles eNodeB outages', $check($enodeb, $engineer)['label']);
        $this->assertTrue($check($transmission, $engineer)['passed']);

        // The NOC cannot hand a ticket to the wrong side either…
        foreach ([[$enodeb, $engineer, 'eNodeB outages: these go to a 4G technician'], [$transmission, $technician, 'Transmission outages: these go to a 4G engineer']] as [$ticket, $wrong, $message]) {
            try {
                $this->workflow->assignMobilityEngineer($ticket, $this->noc, $wrong->id);
                $this->fail('assigned across the engineer / technician split');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($message, $exception->errors()['engineer_id'][0]);
            }
        }

        // …and the assign form only lists the people who handle that kind of outage.
        $this->assertSame([$technician->id], array_keys(MobilityActions::engineerOptions($enodeb->fault_domain)));
        $this->assertSame([$engineer->id], array_keys(MobilityActions::engineerOptions($transmission->fault_domain)));
        $this->assertEqualsCanonicalizing([$engineer->id, $technician->id], array_keys(MobilityActions::engineerOptions()));

        // With no technician available an eNodeB ticket waits: it is never given to an engineer.
        $fifo->setAvailability($technician, AvailabilityStatus::Offline, $technician);
        $this->assertNull($enodeb->refresh()->current_assignee_id);
        $waiting = $this->ticket('mobility_enodeb');
        $this->assertNull($waiting->current_assignee_id);
        $this->assertSame(StatusCode::MobilityNew->value, $waiting->status->code);
        $this->assertSame(1, $engineer->refresh()->activeAssignments()->count(), 'the engineer still only has the transmission ticket');

        // A person given both permissions (People → Roles / Users) works both kinds.
        $technician->givePermissionTo('mobility.handle_transmission');
        $fifo->setAvailability($technician, AvailabilityStatus::Available, $technician);
        $fifo->setAvailability($engineer, AvailabilityStatus::Offline, $engineer);
        $this->assertSame($technician->id, $transmission->refresh()->current_assignee_id);
    }

    public function test_fifo_assigns_4g_engineers_and_follows_timeouts_teams_and_manual_mode(): void
    {
        $fifo = app(FifoAssignmentEngine::class);
        $inQueue = fn (Outage $ticket) => $ticket->refresh()->openQueueEntries()->where('queue_type', QueueType::MobilityEngineer->value)->exists();

        // Nobody available: the ticket waits in the 4G engineer queue as "New".
        $away = $this->makeUser('4G Technician', 'MOBILITY', attributes: ['availability_status' => 'offline']);
        $ticket = $this->ticket('mobility_enodeb');
        $this->assertSame(StatusCode::MobilityNew->value, $ticket->status->code);
        $this->assertSame(QueueType::MobilityEngineer, $ticket->current_queue);
        $this->assertNull($ticket->current_assignee_id);
        $this->assertStringContainsString('queue (FIFO)', $ticket->mobilityOwner());

        // Vendor tickets never enter the engineer queue.
        $this->assertFalse($inQueue($this->ticket('mobility_power', ['vendor_id' => $this->vendor()->id])));

        // An engineer comes on: FIFO assigns the waiting ticket.
        $fifo->setAvailability($away, AvailabilityStatus::Available, $away);
        $this->assertSame($away->id, $ticket->refresh()->current_assignee_id);
        $this->assertSame(StatusCode::MobilityAssigned->value, $ticket->status->code);

        // Not accepted in time → taken back → nobody else available → "Open", still in the queue.
        $this->travel(45)->minutes();
        $fifo->processTimeouts();
        $ticket->refresh();
        $this->assertSame(StatusCode::MobilityOpen->value, $ticket->status->code);
        $this->assertNull($ticket->current_assignee_id);
        $this->assertNull($ticket->technician_id);
        $this->assertTrue($inQueue($ticket));
        $this->assertTrue(OutageUpdate::query()->where('outage_id', $ticket->id)->where('body', 'like', 'Nobody is on the ticket any more%next available 4G technician.')->exists());

        // FIFO can be limited to one team (e.g. Transmission): only its members are offered the ticket.
        $transmission = $this->makeTeam('4G Transmission', 'MOBILITY');
        $ran = $this->makeUser('4G Technician', 'MOBILITY');
        $tx = $this->makeUser('4G Technician', 'MOBILITY', [$transmission]);
        Auth::setUser($this->noc);
        $ticket = $this->workflow->assignMobilityEngineer($ticket, $this->noc, null, 'Microwave side', $transmission->id);
        $this->assertSame($tx->id, $ticket->current_assignee_id, 'the longer-waiting RAN engineer is skipped: not in the team');
        $this->assertSame($transmission->id, $ticket->team_id);
        $this->assertNotSame($ran->id, $ticket->current_assignee_id);

        // The engineer started, then the NOC sends it back to the queue: next engineer starts at "Assigned".
        Auth::setUser($tx);
        $this->workflow->startMobilityWork($ticket, $tx);
        $fifo->setAvailability($tx, AvailabilityStatus::Offline, $tx);
        Auth::setUser($this->noc);
        $ticket = $this->workflow->assignMobilityEngineer($ticket, $this->noc, null);
        $this->assertSame($ran->id, $ticket->current_assignee_id, 'the team is kept only when asked for again');
        $this->assertSame(StatusCode::MobilityAssigned->value, $ticket->status->code);

        // Queue set to manual: tickets wait for the NOC to assign.
        QueueDefinition::query()->where('queue_type', QueueType::MobilityEngineer->value)->update(['assignment_mode' => 'manual']);
        $manual = $this->ticket('mobility_enodeb');
        $this->assertSame(StatusCode::MobilityNew->value, $manual->status->code);
        $this->assertNull($manual->current_assignee_id);
        $manual = $this->workflow->assignMobilityEngineer($manual, $this->noc, $ran->id);
        $this->assertSame($ran->id, $manual->current_assignee_id);
        $this->assertSame(StatusCode::MobilityAssigned->value, $manual->status->code);
    }

    // ------------------------------------------------------------------ vendor workflow

    public function test_power_ticket_emails_the_vendor_and_the_noc_records_vendor_updates(): void
    {
        $vendor = $this->vendor();
        $site = $this->site('4624', 'Nsambya Kevina', $vendor);
        $site->update(['tower_co_site_id' => 'ATC-KLA-0412']);
        app(Settings::class)->set(['mobility.noc_contact' => '+256 700 000 000']);

        $ticket = $this->ticket('mobility_power', ['enodeb_ids' => [$site->id], 'vendor_id' => $vendor->id, 'description' => 'Mains failure', 'location' => 'Nsambya']);

        $this->assertSame('4G-PWR-000001', $ticket->ticket_number);
        $this->assertSame(OutageType::WORKFLOW_MOBILITY_VENDOR, $ticket->workflow);
        $this->assertSame(StatusCode::MobilityVendorNotified->value, $ticket->status->code, 'New → Vendor Assigned → Vendor Notification Sent');
        $this->assertNotNull($ticket->vendor_notified_at);
        $this->assertSame(1, $ticket->power_faults);
        $this->assertSame(
            ['New', 'Vendor Assigned', 'Vendor Notification Sent'],
            $ticket->statusHistories()->with('toStatus')->reorder('id')->get()->pluck('toStatus.name')->all(),
        );

        // E-mail to the vendor record (TO + CC), from the editable template.
        Mail::assertSent(VendorNotificationMail::class, fn (VendorNotificationMail $mail) => $mail->hasTo('ops@atc.example') && $mail->hasCc('lead@atc.example')
            && $mail->log->subject === '[4G POWER OUTAGE] 4G-PWR-000001 - Nsambya Kevina');

        // Audit trail: ticket, vendor, recipient, CC, subject, template, time, status.
        $log = NotificationLog::query()->where('event', VendorNotifier::EVENT)->where('outage_id', $ticket->id)->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertNotNull($log->sent_at);
        $this->assertSame('ops@atc.example', $log->recipient);
        $this->assertSame(['lead@atc.example'], $log->meta['cc']);
        $this->assertSame('ATC', $log->meta['vendor']);
        $this->assertSame('4G Power Outage – Vendor Notification', $log->meta['template']);
        $this->assertStringContainsString('Mains failure', $log->body);
        $this->assertStringContainsString('+256 700 000 000', $log->body);
        // The tower company's own reference for the site: what the vendor's engineer searches by.
        $this->assertStringContainsString('Tower CO ID: ATC-KLA-0412', $log->body);
        $this->assertStringContainsString('4624 Nsambya Kevina (Tower CO ID ATC-KLA-0412)', $log->body);
        $this->assertTrue(OutageUpdate::query()->where('outage_id', $ticket->id)->where('actor_type', 'System')->where('body', 'like', 'Vendor notification e-mail sent to ops@atc.example%')->exists());

        // The vendor has no seat: an engineer cannot be put on a vendor ticket.
        $this->assertFalse($this->noc->can('mobilityAssign', $ticket));

        // NOC captures what the vendor says.
        $update = $this->workflow->recordVendorUpdate($ticket, $this->noc, ['vendor_status' => 'acknowledged', 'method' => 'phone', 'contact_person' => 'Peter']);
        $this->assertSame(StatusCode::MobilityVendorAcknowledged->value, $this->code($ticket));
        $this->assertSame($vendor->id, $update->vendor_id);
        $this->assertNotNull($ticket->vendor_acknowledged_at);

        $this->workflow->recordVendorUpdate($ticket, $this->noc, [
            'vendor_status' => 'technician_dispatched', 'method' => 'whatsapp', 'eta_at' => now()->addHour(), 'next_update_at' => now()->addMinutes(30), 'comments' => 'Team leaving the depot',
        ]);
        $this->assertSame(StatusCode::MobilityVendorInvestigating->value, $this->code($ticket));
        $this->assertNotNull($ticket->vendor_eta_at);
        $this->assertStringContainsString('Technician Dispatched', $ticket->latest_update);

        // An engineer role cannot record vendor updates.
        $engineer = $this->makeUser('4G Engineer', 'MOBILITY');
        $this->assertFalse($engineer->can('mobilityVendorUpdate', $ticket));

        $this->workflow->recordVendorUpdate($ticket, $this->noc, ['vendor_status' => 'power_restored', 'method' => 'phone']);
        $this->assertSame(StatusCode::MobilityVendorResolvedPendingNoc->value, $this->code($ticket));
        $this->assertFalse($ticket->is_closed);
        $this->assertSame(QueueType::MobilityNoc, $ticket->current_queue);

        $ticket = $this->workflow->closeMobility($ticket, $this->noc, ['root_cause' => 'Mains failure, generator out of fuel', 'resolution' => 'Generator refuelled by ATC']);
        $this->assertSame(StatusCode::MobilityClosed->value, $ticket->status->code);
        $this->assertSame(3, $ticket->vendorUpdates()->count());

        // The vendor is told it is closed (its own template).
        $this->assertTrue(NotificationLog::query()->where('event', VendorNotifier::EVENT)->where('outage_id', $ticket->id)->where('subject', 'like', '[RESTORED]%')->where('status', 'sent')->exists());
    }

    public function test_templates_of_a_running_system_get_the_tower_co_id_line(): void
    {
        $migration = require database_path('migrations/2026_10_04_100001_add_tower_co_id_to_vendor_templates.php');
        $body = fn (string $code) => VendorTemplate::query()->where('code', $code)->value('body');
        $set = fn (string $code, string $text) => VendorTemplate::query()->where('code', $code)->update(['body' => $text]);

        // As they were before: no Tower CO ID. One was edited in a browser (CRLF), one names the
        // site inside a sentence, one was rewritten by an administrator without a site line.
        $set('power_vendor_notification', "Dear team,\r\n\r\n**Site ID:** {{site_id}}\r\n**Site name:** {{site_name}}\r\n");
        $set('vendor_reminder', "**Ticket number:** {{ticket_number}}\n**Site:** {{site_id}} {{site_name}}\n**Priority:** {{priority}}");
        $set('vendor_cancelled', 'Ticket {{ticket_number}} for site {{site_id}} {{site_name}} has been cancelled.');
        $set('vendor_closed', 'Our own wording, {{ticket_number}}.');
        $reopened = $body('vendor_reopened');

        $migration->up();
        $migration->up(); // running it twice adds nothing twice

        $this->assertSame("Dear team,\r\n\r\n**Site ID:** {{site_id}}\r\n**Tower CO ID:** {{tower_co_id}}\r\n**Site name:** {{site_name}}\r\n", $body('power_vendor_notification'));
        $this->assertSame("**Ticket number:** {{ticket_number}}\n**Site:** {{site_id}} {{site_name}}\n**Tower CO ID:** {{tower_co_id}}\n**Priority:** {{priority}}", $body('vendor_reminder'));
        $this->assertSame('Ticket {{ticket_number}} for site {{site_id}} {{site_name}} (Tower CO ID {{tower_co_id}}) has been cancelled.', $body('vendor_cancelled'));
        $this->assertSame('Our own wording, {{ticket_number}}.', $body('vendor_closed'), 'no site line: left as written');
        $this->assertSame($reopened, $body('vendor_reopened'), 'already has the placeholder');

        // Several sites: each tower company reference with the site it belongs to.
        $vendor = $this->vendor();
        $one = tap($this->site('4624', 'Nsambya Kevina', $vendor))->update(['tower_co_site_id' => 'ATC-1']);
        $two = $this->site('4625', 'Kabowa', $vendor);
        $three = tap($this->site('4626', 'Lukuli', $vendor))->update(['tower_co_site_id' => 'ATC-3']);
        $ticket = $this->ticket('mobility_power', ['enodeb_ids' => [$one->id, $two->id, $three->id], 'vendor_id' => $vendor->id]);

        $data = app(VendorNotifier::class)->data($ticket);
        $this->assertSame('ATC-1 (4624), ATC-3 (4626)', $data['tower_co_id']);
        $this->assertSame('4624 Nsambya Kevina (Tower CO ID ATC-1), 4625 Kabowa, 4626 Lukuli (Tower CO ID ATC-3)', $data['affected_sites']);

        $migration->down();
        $this->assertSame("**Ticket number:** {{ticket_number}}\n**Site:** {{site_id}} {{site_name}}\n**Priority:** {{priority}}", $body('vendor_reminder'));
    }

    public function test_vendor_problems_are_visible_and_inactive_vendors_cannot_be_selected(): void
    {
        $noMail = $this->vendor('TOA', null);
        $ticket = $this->ticket('mobility_power', ['vendor_id' => $noMail->id]);

        // No address: nothing sent, the ticket stays "Vendor Assigned" and the NOC is told why.
        $this->assertSame(StatusCode::MobilityVendorAssigned->value, $ticket->status->code);
        Mail::assertNotSent(VendorNotificationMail::class);
        $log = NotificationLog::query()->where('event', VendorNotifier::EVENT)->where('outage_id', $ticket->id)->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('no e-mail address', $log->failure_reason);
        $this->assertTrue(OutageUpdate::query()->where('outage_id', $ticket->id)->where('body', 'like', 'Vendor notification NOT sent%')->exists());

        // Address added → "Send vendor notification" delivers and moves the ticket on.
        $noMail->update(['email' => 'noc@toa.example']);
        $ticket = $this->workflow->resendVendorNotification($ticket, $this->noc);
        $this->assertSame(StatusCode::MobilityVendorNotified->value, $ticket->status->code);
        Mail::assertSent(VendorNotificationMail::class, fn (VendorNotificationMail $mail) => $mail->hasTo('noc@toa.example'));

        // Changing the vendor e-mails the new one and restarts the vendor steps.
        $atc = $this->vendor('ATC');
        $ticket = $this->workflow->changeMobilityVendor($ticket, $this->noc, $atc->id, 'Site is on an ATC tower');
        $this->assertSame($atc->id, $ticket->vendor_id);
        $this->assertSame(StatusCode::MobilityVendorNotified->value, $ticket->status->code);
        Mail::assertSent(VendorNotificationMail::class, fn (VendorNotificationMail $mail) => $mail->hasTo('ops@atc.example'));

        // Vendor required, and it must be active.
        foreach ([[], ['vendor_id' => tap($this->vendor('UTEL'))->update(['is_active' => false])->id]] as $data) {
            try {
                $this->ticket('mobility_power', $data);
                $this->fail('a power ticket needs an active vendor');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('vendor_id', $exception->errors());
            }
        }

        // A template can be switched off: no template, no e-mail, and the timeline says so.
        VendorTemplate::query()->update(['is_active' => false]);
        $silent = $this->ticket('mobility_power', ['vendor_id' => $atc->id]);
        $this->assertSame(StatusCode::MobilityVendorAssigned->value, $silent->status->code);
        $this->assertTrue(OutageUpdate::query()->where('outage_id', $silent->id)->where('body', 'like', 'No active vendor e-mail template%')->exists());
    }

    // ------------------------------------------------------------------ reopen / cancel / escalate

    public function test_closed_tickets_can_be_reopened_with_a_reason_and_cancelled_tickets_have_no_sla(): void
    {
        $engineer = $this->makeUser('4G Technician', 'MOBILITY');
        $ticket = $this->ticket('mobility_enodeb', ['engineer_id' => $engineer->id]);
        $this->assertSame(StatusCode::MobilityAssigned->value, $ticket->status->code, 'engineer chosen on the form');

        Auth::setUser($engineer);
        $ticket = $this->workflow->markMobilityResolved($ticket, $engineer, ['root_cause' => 'Software hang', 'resolution' => 'eNodeB restarted']);
        Auth::setUser($this->noc);
        $ticket = $this->workflow->closeMobility($ticket, $this->noc);
        $closedAt = $ticket->resolved_at;

        $this->assertFalse($engineer->can('mobilityReopen', $ticket));

        try {
            $this->workflow->reopenMobility($ticket, $this->noc, ' ');
            $this->fail('a reason is required');
        } catch (ValidationException) {
        }

        // Reopened → back into the workflow: FIFO hands it to the next available engineer.
        $ticket = $this->workflow->reopenMobility($ticket, $this->noc, 'Site went down again after 20 minutes');
        $this->assertSame(StatusCode::MobilityAssigned->value, $ticket->status->code);
        $this->assertSame($engineer->id, $ticket->current_assignee_id);
        $this->assertContains('Open', $ticket->statusHistories()->with('toStatus')->get()->pluck('toStatus.name')->all());
        $this->assertFalse($ticket->is_closed);
        $this->assertSame(1, $ticket->reopen_count);
        $this->assertNull($ticket->resolved_at);
        $this->assertSame(SlaState::Compliant, $ticket->sla_state, 'the SLA clock runs again from the original start');

        $reopening = $ticket->reopenings()->firstOrFail();
        $this->assertSame($this->noc->id, $reopening->user_id);
        $this->assertSame('Site went down again after 20 minutes', $reopening->reason);
        $this->assertSame('Closed', $reopening->previousStatus->name);
        $this->assertTrue($closedAt->equalTo($reopening->previous_closed_at));
        $this->assertSame('Software hang', $reopening->previous_root_cause);
        $this->assertSame(SlaState::Met->value, $reopening->previous_sla_state);

        // Back in the workflow: escalate, then cancel.
        $manager = $this->makeUser('4G NOC Manager', 'MOBILITY', attributes: ['email' => 'mgr4g@example.com', 'availability_status' => 'offline']);
        $ticket = $this->workflow->escalateMobility($ticket, $this->noc, 'Third failure this week');
        $this->assertNotNull($ticket->escalated_at);
        $this->assertTrue(NotificationLog::query()->where('event', 'mobility_escalated')->where('recipient_user_id', $manager->id)->exists());

        $ticket = $this->workflow->cancelMobility($ticket, $this->noc, 'Duplicate of 4G-ENB-000009');
        $this->assertSame(StatusCode::MobilityCancelled->value, $ticket->status->code);
        $this->assertTrue($ticket->is_closed);
        $this->assertSame(SlaState::NotApplicable, $ticket->sla_state);
    }

    public function test_a_silent_vendor_is_chased_then_escalated_to_management(): void
    {
        $manager = $this->makeUser('4G NOC Manager', 'MOBILITY', attributes: ['email' => 'mgr4g@example.com', 'availability_status' => 'offline']);
        app(Settings::class)->set(['mobility.vendor_silent_minutes' => 30, 'mobility.vendor_escalation_minutes' => 90, 'mobility.escalation_emails' => ['cto@example.com']]);
        $ticket = $this->ticket('mobility_power', ['vendor_id' => $this->vendor()->id]);
        $monitor = app(VendorEscalationMonitor::class);
        $sentTo = fn (string $event, string $recipient) => NotificationLog::query()->where('outage_id', $ticket->id)->where('event', $event)->where('recipient', $recipient)->exists();

        $this->assertSame(0, $monitor->tick());

        // 30 minutes of silence → the NOC is reminded (once).
        $this->travel(31)->minutes();
        $this->assertSame(1, $monitor->tick());
        $this->assertSame(0, $monitor->tick());
        $this->assertSame(1, $ticket->refresh()->vendor_escalation_level);
        $this->assertTrue($sentTo('mobility_vendor_silent', 'noc4g@example.com'));

        // A vendor update restarts the clock, and a promised "next update" time is respected.
        $this->workflow->recordVendorUpdate($ticket, $this->noc, ['vendor_status' => 'investigating', 'method' => 'phone', 'next_update_at' => now()->addMinutes(45)]);
        $this->assertSame(0, $ticket->refresh()->vendor_escalation_level);
        $this->travel(40)->minutes();
        $this->assertSame(0, $monitor->tick());
        $this->travel(6)->minutes();
        $this->assertSame(1, $monitor->tick());

        // Still nothing → management and the vendor (reminder e-mail).
        $this->travel(61)->minutes();
        $this->assertSame(1, $monitor->tick());
        $this->assertSame(2, $ticket->refresh()->vendor_escalation_level);
        $this->assertTrue($sentTo('mobility_vendor_escalation', 'cto@example.com'));
        $this->assertTrue($sentTo('mobility_vendor_escalation', 'mgr4g@example.com'));
        $this->assertTrue(NotificationLog::query()->where('event', VendorNotifier::EVENT)->where('outage_id', $ticket->id)->where('subject', 'like', '[REMINDER]%')->exists());
        $this->assertSame(0, $monitor->tick());

        // Restored → no longer waiting on the vendor.
        $this->workflow->recordVendorUpdate($ticket, $this->noc, ['vendor_status' => 'resolved', 'method' => 'email']);
        $this->travel(5)->hours();
        $this->assertSame(0, $monitor->tick());
    }

    // ------------------------------------------------------------------ audit trail

    public function test_every_4g_action_is_in_the_audit_trail(): void
    {
        Storage::fake('local');
        $engineer = $this->makeUser('4G Technician', 'MOBILITY');
        $events = fn (Outage $ticket) => AuditLog::query()->where('auditable_type', $ticket->getMorphClass())->where('auditable_id', $ticket->id)
            ->where('event', 'like', '4g_%')->orderBy('id')->pluck('event')->all();

        // Internal ticket: created → FIFO → engineer → NOC.
        $internal = $this->ticket('mobility_enodeb');
        Auth::setUser($engineer);
        $this->workflow->startMobilityWork($internal, $engineer);
        $this->workflow->addUpdate($internal, $engineer, 'Replacing the RRU', 'progress');
        $this->workflow->markMobilityResolved($internal, $engineer, ['root_cause' => 'Faulty RRU', 'resolution' => 'Replaced']);
        Auth::setUser($this->noc);
        $this->workflow->rejectMobilityResolution($internal, $this->noc, 'Cell 2 still down');
        $this->workflow->assignMobilityEngineer($internal, $this->noc, $engineer->id, 'Check the jumper');

        $this->assertSame([
            '4g_created', '4g_queued_for_engineer', '4g_status_changed', '4g_fifo_assigned', '4g_status_changed', '4g_investigation_started',
            '4g_update_posted', '4g_status_changed', '4g_resolved_pending_noc', '4g_status_changed', '4g_verification_failed',
            '4g_status_changed', '4g_engineer_assigned',
        ], $events($internal));

        // Who, action, previous status, new status, comment.
        $change = AuditLog::query()->where('auditable_id', $internal->id)->where('event', '4g_status_changed')->orderBy('id')->get()[1];
        $this->assertSame($engineer->id, $change->user_id);
        $this->assertSame('Assigned', $change->old_values['status']);
        $this->assertSame('In Progress', $change->new_values['status']);
        $this->assertSame($internal->ticket_number, $change->subjectLabel());
        $this->assertSame('4G: Status changed', $change->eventLabel());

        $fifo = AuditLog::query()->where('auditable_id', $internal->id)->where('event', '4g_fifo_assigned')->firstOrFail();
        $this->assertNull($fifo->user_id, 'FIFO assignments are the system’s, not the signed-in user’s');
        $this->assertSame('System', $fifo->new_values['by']);
        $this->assertSame('4G: FIFO assigned', $fifo->eventLabel());

        // Vendor ticket: vendor, e-mail, vendor update, attachment, close, reopen, escalate, cancel.
        $vendor = $this->vendor();
        $power = $this->ticket('mobility_power', ['vendor_id' => $vendor->id]);
        Storage::disk('local')->put('outage-attachments/2026/10/mail.eml', 'x');
        $this->workflow->recordVendorUpdate($power, $this->noc, [
            'vendor_status' => 'power_restored', 'method' => 'email', 'comments' => 'Mains back',
            'attachments' => ['outage-attachments/2026/10/mail.eml'], 'attachment_names' => ['outage-attachments/2026/10/mail.eml' => 'mail.eml'],
        ]);
        $this->workflow->addMobilityAttachments($power, $this->noc, ['outage-attachments/2026/10/mail.eml'], [], 'Site photo');
        $this->workflow->closeMobility($power, $this->noc, ['root_cause' => 'Mains failure', 'resolution' => 'Restored by ATC']);
        $this->workflow->reopenMobility($power, $this->noc, 'Down again');
        $this->workflow->changeMobilityVendor($power, $this->noc, $this->vendor('TOA', 'noc@toa.example')->id, 'Site is on a TOA tower');
        $this->workflow->escalateMobility($power, $this->noc, 'Second failure today');
        $this->workflow->cancelMobility($power, $this->noc, 'Duplicate');

        $recorded = $events($power);
        foreach ([
            '4g_created', '4g_vendor_assigned', '4g_vendor_email_sent', '4g_vendor_update', '4g_attachment_added', '4g_closed',
            '4g_reopened', '4g_vendor_changed', '4g_escalated', '4g_cancelled', '4g_status_changed',
        ] as $event) {
            $this->assertContains($event, $recorded, $event);
        }

        $mail = AuditLog::query()->where('auditable_id', $power->id)->where('event', '4g_vendor_email_sent')->firstOrFail();
        $this->assertStringContainsString('ops@atc.example', $mail->new_values['details']);
        $closed = AuditLog::query()->where('auditable_id', $power->id)->where('event', '4g_closed')->firstOrFail();
        $this->assertSame($this->noc->id, $closed->user_id);
        $this->assertSame('Closed', $closed->new_values['status']);

        // The records behind a ticket are audited too, and so is the 4G configuration.
        $vendor->update(['phone' => '+256700000001']);
        app(Settings::class)->set(['mobility.vendor_silent_minutes' => 45], $this->noc->id);
        $ftth = $this->reportOutage($this->makeUser('CX Agent', 'CX'));

        $scope = AuditLog::query()->mobility()->get();
        $types = $scope->pluck('auditable_type')->map(fn (string $type) => class_basename($type))->unique()->values()->all();
        foreach (['Outage', 'Vendor', 'VendorUpdate', 'OutageAttachment', 'OutageReopening', 'Setting'] as $type) {
            $this->assertContains($type, $types, $type);
        }
        $this->assertTrue($scope->contains(fn (AuditLog $log) => $log->auditable_type === $power->getMorphClass() && $log->auditable_id === $power->id && $log->event === 'updated'), 'field changes of 4G tickets');
        $this->assertFalse($scope->contains(fn (AuditLog $log) => $log->auditable_type === $ftth->getMorphClass() && $log->auditable_id === $ftth->id), 'FTTH tickets are not part of the 4G trail');
        $this->assertFalse($scope->contains(fn (AuditLog $log) => class_basename($log->auditable_type) === 'Priority'));

        // A silent vendor is in the trail as well.
        $silent = $this->ticket('mobility_power', ['vendor_id' => $vendor->id]);
        $this->travel(61)->minutes();
        app(VendorEscalationMonitor::class)->tick();
        $this->assertContains('4g_vendor_no_update', $events($silent));
    }

    // ------------------------------------------------------------------ notifications: 4G is separate from FTTH

    public function test_4g_tickets_use_the_4g_rules_and_alert_lists_only(): void
    {
        app(Settings::class)->set([
            'alerts.emails' => ['ftth-alerts@example.com'],
            'mobility.alert_emails' => ['4g-alerts@example.com'],
            'mobility.alert_phones' => ['+256700111222'],
        ]);
        $ftthLead = $this->makeUser('NOC Team Leader', 'NOC', attributes: ['email' => 'ftth.lead@example.com', 'availability_status' => 'offline']);
        $agent = $this->makeUser('CX Agent', 'CX');

        $ticket = $this->ticket('mobility_enodeb');
        $recipients = fn (Outage $outage) => NotificationLog::query()->where('outage_id', $outage->id)->where('event', 'outage_created')->pluck('recipient')->all();

        $this->assertContains('4g-alerts@example.com', $recipients($ticket));
        $this->assertContains('+256700111222', $recipients($ticket), 'SMS alert list of 4G');
        $this->assertContains('noc4g@example.com', $recipients($ticket), '4G rule: 4G NOC role');
        $this->assertNotContains('ftth-alerts@example.com', $recipients($ticket));
        $this->assertNotContains('ftth.lead@example.com', $recipients($ticket), 'FTTH rules never fire for a 4G ticket');

        // …and an FTTH ticket never reaches the 4G audience.
        $ftth = $this->reportOutage($agent);
        $this->assertContains('ftth-alerts@example.com', $recipients($ftth));
        $this->assertContains('ftth.lead@example.com', $recipients($ftth));
        $this->assertNotContains('4g-alerts@example.com', $recipients($ftth));
        $this->assertNotContains('noc4g@example.com', $recipients($ftth));

        // Visibility is separate too.
        $this->assertFalse($agent->can('view', $ticket));
        $this->assertTrue($this->noc->can('view', $ticket));
        $this->assertFalse($this->noc->can('view', $ftth));
        $this->assertFalse($agent->can('createMobility', Outage::class));
    }
}
