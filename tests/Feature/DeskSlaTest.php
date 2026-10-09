<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Filament\Pages\FifoSettings;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\OutageQueueEntry;
use App\Models\TicketSubcategory;
use App\Services\Outages\OutageWorkflow;
use App\Services\Reports\DeskSlaReports;
use App\Services\Reports\ReportRegistry;
use App\Services\Sla\DeskSla;
use App\Services\Sla\SlaService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * SLA per desk: each desk has its own time to act on a ticket (NOC 20 minutes, CX Tier II 15,
 * the CX frontline 5), an outage is on the field within 30 minutes, the people concerned are
 * warned before and told at the breach — once — and the breaches are reported per desk.
 */
class DeskSlaTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->seedReference();
        Mail::fake();
        $this->workflow = app(OutageWorkflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function entry(Outage $outage, QueueType $desk): OutageQueueEntry
    {
        return $outage->queueEntries()->where('queue_type', $desk->value)->latest('id')->firstOrFail();
    }

    private function events(Outage $outage, string $event): int
    {
        return NotificationLog::query()->where('outage_id', $outage->id)->where('event', $event)->count();
    }

    public function test_the_built_in_targets_are_noc_20_tier_two_15_and_the_frontline_5(): void
    {
        $targets = app(DeskSla::class)->targets();

        $this->assertSame(20, $targets['noc_review']);
        $this->assertSame(20, $targets['noc_restore']);
        $this->assertSame(20, $targets['ticket_noc']);
        $this->assertSame(20, $targets['mobility_noc']);
        $this->assertSame(15, $targets['cx_tier2']);
        $this->assertSame(5, $targets['cx_callback']);
        $this->assertSame(0, $targets['technician'], 'a desk without a target has none until one is set');
        $this->assertSame(30, app(DeskSla::class)->fieldMinutes());
    }

    public function test_noc_is_warned_before_its_20_minutes_are_up_and_told_once_when_they_are(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $manager = $this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']);
        $billing = $this->makeUser('Billing Manager', 'BILLING', attributes: ['availability_status' => 'offline']);

        $outage = $this->reportOutage($agent);
        $entry = $this->entry($outage, QueueType::NocReview);

        $this->assertSame(20, $entry->sla_minutes);
        $this->assertSame('2026-10-05 10:20:00', $entry->sla_due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 10:15:00', $entry->sla_warn_at->format('Y-m-d H:i:s'), 'warned at 75 % of the target');

        $this->travel(10)->minutes();
        $this->assertSame(0, array_sum(app(DeskSla::class)->check()));

        $this->travel(6)->minutes();
        $this->assertSame(1, app(DeskSla::class)->check()['warned']);
        $this->assertSame(0, array_sum(app(DeskSla::class)->check()), 'the warning is not repeated');

        $warning = NotificationLog::query()->where('outage_id', $outage->id)->where('event', 'desk_sla_pre_breach')->get();
        $this->assertEqualsCanonicalizing([$noc->id, $manager->id], $warning->pluck('recipient_user_id')->all(), 'the person holding it and the stakeholders who may view outages');
        $this->assertNotContains($billing->id, $warning->pluck('recipient_user_id')->all());
        $this->assertSame(['database'], $warning->pluck('channel')->unique()->values()->all(), 'a warning is not e-mailed');
        $this->assertStringContainsString('NOC Review', $warning->first()->subject);

        $this->travel(5)->minutes();
        $this->assertSame(1, app(DeskSla::class)->check()['breached']);
        $this->assertSame(0, array_sum(app(DeskSla::class)->check()));

        $breach = NotificationLog::query()->where('outage_id', $outage->id)->where('event', 'desk_sla_breach')->get();
        $this->assertSame(2, $breach->where('channel', 'database')->count());
        $this->assertSame(2, $breach->where('channel', 'mail')->count(), 'a breach is e-mailed too');
        $this->assertStringContainsString('past its target of 20 min', $breach->first()->body);
        $this->assertStringContainsString("with {$noc->name}", $breach->first()->body);
        $this->assertNotNull($this->entry($outage, QueueType::NocReview)->sla_breached_at);
        $this->assertSame(1, $outage->slaEvents()->where('event', 'desk_breached')->count());
    }

    public function test_a_desk_that_acts_in_time_is_never_reported(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $this->makeUser('Infrastructure Dispatcher', 'INFRA');
        $outage = $this->reportOutage($agent);

        $this->travel(8)->minutes();
        Auth::setUser($noc);
        $outage = $this->workflow->confirmByNoc($outage, $noc, 'Alarm confirmed');

        $this->travel(30)->minutes();
        app(DeskSla::class)->check();

        $this->assertNull($this->entry($outage, QueueType::NocReview)->sla_breached_at);
        $this->assertSame(0, $this->events($outage, 'desk_sla_breach'));
        // Infrastructure dispatch has no target of its own by default…
        $this->assertNull($this->entry($outage, QueueType::Infrastructure)->sla_due_at);
    }

    public function test_an_outage_not_on_the_field_within_30_minutes_is_warned_and_then_breached(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $this->makeUser('Outage Technician', 'INFRA', [$team]);
        $manager = $this->makeUser('Infrastructure Manager', 'INFRA', attributes: ['availability_status' => 'offline']);

        $late = $this->reportOutage($agent);
        $onTime = $this->reportOutage($agent);
        $this->assertSame('2026-10-05 10:30:00', $late->field_due_at->format('Y-m-d H:i:s'));

        Auth::setUser($noc);
        $this->workflow->confirmByNoc($late, $noc);
        $onTime = $this->workflow->confirmByNoc($onTime, $noc);
        Auth::setUser($dispatcher);
        $this->workflow->dispatch($onTime, ['team_id' => $team->id], $dispatcher);

        $this->travel(23)->minutes();
        $result = app(DeskSla::class)->check();
        $this->assertSame(1, $result['field_warned']);
        $this->assertSame(1, $this->events($late, 'field_sla_pre_breach') > 0 ? 1 : 0);

        $this->travel(8)->minutes();
        $this->assertSame(1, app(DeskSla::class)->check()['field_breached']);
        $this->assertSame(0, app(DeskSla::class)->check()['field_breached']);

        $breach = NotificationLog::query()->where('outage_id', $late->id)->where('event', 'field_sla_breach')->get();
        $this->assertContains($manager->id, $breach->pluck('recipient_user_id')->all());
        $this->assertStringContainsString('Not dispatched to a field technician within 30 min', $breach->first()->body);
        $this->assertStringContainsString('Infrastructure Dispatch', $breach->first()->body);
        $this->assertSame(0, $this->events($onTime, 'field_sla_breach'), 'dispatched in time');
        $this->assertSame(0, $this->events($onTime, 'field_sla_pre_breach'));
    }

    public function test_an_outage_noc_rejects_is_no_longer_expected_on_the_field_but_tier_two_has_15_minutes(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $this->makeUser('CX Tier II', 'CX');
        $outage = $this->reportOutage($agent);

        Auth::setUser($noc);
        $outage = $this->workflow->rejectByNoc($outage, $noc, 'Customer CPE fault, not an outage');

        $this->assertNull($outage->field_due_at);
        $this->assertSame(15, $this->entry($outage, QueueType::CxFollowUp)->sla_minutes);

        // The ticket SLA is paused for a rejected outage; the desk still has its own time to call the customer.
        $this->travel(40)->minutes();
        $result = app(DeskSla::class)->check();

        $this->assertSame(1, $result['breached']);
        $this->assertSame(0, $result['field_breached']);
        $this->assertStringContainsString('CX Follow-up', NotificationLog::query()->where('event', 'desk_sla_breach')->firstOrFail()->subject);
    }

    public function test_customer_ticket_desks_count_working_hours_and_a_changed_target_only_applies_to_new_arrivals(): void
    {
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $this->makeUser('Billing Agent', 'BILLING');
        Auth::setUser($frontline);
        app(Settings::class)->set(['desk_sla.minutes' => ['billing' => 30]]);

        $raise = fn () => $this->workflow->createTicket([
            'subcategory_id' => TicketSubcategory::query()->where('name', 'Wrong bill / double charge')->value('id'),
            'account_number' => 'SF2577916', 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001',
        ], $frontline);

        $first = $this->entry($raise(), QueueType::Billing);
        $this->assertSame(30, $first->sla_minutes);
        $this->assertSame('2026-10-05 10:30:00', $first->sla_due_at->format('Y-m-d H:i:s'));

        // Raised after hours: the desk's time starts with the next working day (07:00–18:00).
        Carbon::setTestNow('2026-10-05 19:10:00');
        $evening = $this->entry($raise(), QueueType::Billing);
        $this->assertSame('2026-10-06 07:30:00', $evening->sla_due_at->format('Y-m-d H:i:s'));

        app(Settings::class)->set(['desk_sla.minutes' => ['billing' => 60]]);
        $this->assertSame(30, $first->refresh()->sla_minutes, 'what already happened keeps the target it had');
        $this->assertSame(60, $this->entry($raise(), QueueType::Billing)->sla_minutes);
        $this->assertSame(20, app(DeskSla::class)->minutesFor(QueueType::NocReview), 'the other desks keep their built-in targets');

        app(Settings::class)->set(['desk_sla.enabled' => false]);
        $this->assertNull($this->entry($raise(), QueueType::Billing)->sla_due_at);
    }

    public function test_the_ticket_sla_breach_names_the_desk_the_ticket_was_at(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $outage = $this->reportOutage($agent, ['customers_affected' => 500, 'start_time' => now()]);

        $this->travel(260)->minutes();
        app(SlaService::class)->check();

        $event = $outage->slaEvents()->where('event', 'breached')->firstOrFail();
        $this->assertSame('noc_review', $event->meta['desk']);
        $this->assertSame($noc->id, $event->meta['held_by_id']);
        $this->assertStringContainsString('Queue: NOC Review', NotificationLog::query()->where('outage_id', $outage->id)->where('event', 'sla_breach')->firstOrFail()->body);
    }

    public function test_the_reports_list_every_breach_with_its_desk_for_the_modules_a_person_may_view(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $this->makeUser('Billing Agent', 'BILLING');
        app(Settings::class)->set(['desk_sla.minutes' => ['billing' => 30]]);

        $slow = $this->reportOutage($agent);
        $quick = $this->reportOutage($agent);
        Auth::setUser($frontline);
        $ticket = $this->workflow->createTicket([
            'subcategory_id' => TicketSubcategory::query()->where('name', 'Wrong bill / double charge')->value('id'),
            'account_number' => 'SF2577916', 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001',
        ], $frontline);

        $this->travel(5)->minutes();
        Auth::setUser($noc);
        $this->workflow->rejectByNoc($quick, $noc, 'Not an outage');

        $this->travel(40)->minutes();
        app(DeskSla::class)->check();
        $this->workflow->rejectByNoc($slow, $noc, 'Not an outage either');

        $this->actingAs($this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']));
        $this->assertArrayHasKey('desk_sla_breaches', ReportRegistry::availableTo(Auth::user()));

        $breaches = collect(app(ReportRegistry::class)->run('desk_sla_breaches')->rows());
        $nocBreach = $breaches->where('ticket', $slow->ticket_number)->where('kind', DeskSlaReports::KIND_DESK)->where('desk', 'NOC Review')->first();

        $this->assertNotNull($nocBreach);
        $this->assertSame(20, $nocBreach['target']);
        $this->assertSame(45, $nocBreach['taken']);
        $this->assertSame(25, $nocBreach['over']);
        $this->assertSame($noc->name, $nocBreach['held_by']);
        $this->assertSame('Handled late', $nocBreach['state']);
        $this->assertTrue($breaches->where('ticket', $ticket->ticket_number)->where('desk', 'Billing')->where('state', 'Still waiting')->isNotEmpty());
        $this->assertTrue($breaches->where('ticket', $quick->ticket_number)->where('desk', 'NOC Review')->isEmpty(), 'handled within the target');
        $this->assertTrue($breaches->where('ticket', $slow->ticket_number)->where('kind', DeskSlaReports::KIND_FIELD)->isNotEmpty(), 'it was never dispatched within 30 minutes');

        $desks = collect(app(ReportRegistry::class)->run('desk_sla_performance')->rows())->keyBy('desk');
        $this->assertSame(2, $desks['NOC Review']['arrived']);
        $this->assertSame(1, $desks['NOC Review']['within']);
        $this->assertSame(1, $desks['NOC Review']['breached']);
        $this->assertSame('50%', $desks['NOC Review']['compliance']);
        $this->assertSame(1, $desks['Billing']['waiting_breached']);

        // Someone who may only view outages does not see the customer ticket's desks.
        $this->actingAs($this->makeUser('IT Engineer', 'IT', attributes: ['availability_status' => 'offline']));
        $own = collect(app(ReportRegistry::class)->run('desk_sla_breaches')->rows());
        $this->assertTrue($own->where('ticket', $ticket->ticket_number)->isEmpty());
        $this->assertTrue($own->where('ticket', $slow->ticket_number)->isNotEmpty());
    }

    public function test_the_targets_are_set_per_desk_on_the_settings_page(): void
    {
        $this->actingAs($this->makeUser('Super Admin', 'NOC', attributes: ['availability_status' => 'offline']));

        Livewire::test(FifoSettings::class)
            ->assertSet('data.desk_sla__minutes.noc_review', 20)
            ->assertSet('data.desk_sla__minutes.cx_callback', 5)
            ->set('data.desk_sla__minutes.noc_review', 25)
            ->set('data.desk_sla__minutes.billing', 45)
            ->set('data.desk_sla__field_minutes', 40)
            ->call('save')
            ->assertHasNoErrors();

        $desks = app(DeskSla::class);
        $this->assertSame(25, $desks->minutesFor(QueueType::NocReview));
        $this->assertSame(45, $desks->minutesFor(QueueType::Billing));
        $this->assertSame(15, $desks->minutesFor(QueueType::CxTier2));
        $this->assertSame(40, $desks->fieldMinutes());
    }
}
