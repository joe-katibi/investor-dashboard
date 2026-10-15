<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Filament\Resources\SiteVisits\Pages\ListSiteVisits;
use App\Filament\Resources\SiteVisits\Pages\ViewSiteVisit;
use App\Models\FinalReason;
use App\Models\Outage;
use App\Models\Ticket;
use App\Models\TicketFollowUp;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use App\Services\Reports\ReportRegistry;
use App\Services\Tickets\TicketFollowUpMonitor;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * 72 hours after CX Field Support confirms a site visit, CX Follow-up – Site Visit calls the customer:
 * happy → closed with the feedback; the same issue → the visit is reopened; a new issue → a new
 * ticket; not reached → called again, then closed as unreachable. Reported on.
 */
class VisitAftercareTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    /** @var array{0: User, 1: User, 2: User}|null dispatcher, technician, CX Field Support — the same for every visit */
    private ?array $staff = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function photo(string $name): string
    {
        Storage::disk('local')->put($path = 'outage-attachments/2026/10/'.$name, 'image');

        return $path;
    }

    /** A support visit worked, verified and confirmed by CX Field Support: closed. */
    private function closedVisit(string $account = 'SF2659301'): Outage
    {
        [$dispatcher, $technician, $fieldSupport] = $this->staff ??= [
            $this->makeUser('Site Visit Dispatcher', 'DISPATCH'), $this->makeUser('Site Visit Technician', 'DISPATCH'), $this->makeUser('CX Field Support', 'CX'),
        ];
        Auth::setUser($dispatcher);

        $visit = $this->workflow->createSiteVisit([
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'),
            'subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => $account, 'customer_name' => 'John Customer', 'calling_number' => '0772000010', 'address' => 'Lukuli',
        ], $dispatcher);
        $visit = $this->workflow->assignVisit($visit, $dispatcher, ['technician_id' => $technician->id]);
        $visit = $this->workflow->visitArrive($visit, $technician);
        $visit = $this->workflow->completeVisit($visit, $technician, ['final_reason_id' => FinalReason::query()->where('name', 'ONU reset / configured')->value('id'),
            'resolution_notes' => 'Reset', 'fat_number' => 'FAT-1', 'onu_status' => 'existing', 'geo_photo' => $this->photo("geo-{$account}.jpg"), 'speed_photo' => $this->photo("speed-{$account}.jpg")]);
        $visit = $this->workflow->verifyVisit($visit, $dispatcher, 'Online');
        $visit = $this->workflow->confirmVisit($visit, $fieldSupport, ['outcome' => 'confirmed', 'notes' => 'Working']);
        $this->assertSame(StatusCode::VisitClosed->value, $visit->status->code);

        return $visit;
    }

    private function details(Outage $outage): Ticket
    {
        return Ticket::query()->where('outage_id', $outage->id)->firstOrFail();
    }

    /** 72 hours on, with an agent on shift: the call is due and given to the agent. */
    private function dueFor(Outage $visit): User
    {
        Carbon::setTestNow(now()->addHours(72)->addMinutes(5));
        $agent = $this->makeUser('CX Follow-up – Site Visit Agent', 'CX');
        app(TicketFollowUpMonitor::class)->tick();

        return $agent;
    }

    private function holder(Outage $visit): ?int
    {
        return $visit->openQueueEntries()->where('queue_type', QueueType::VisitAftercare->value)->first()?->activeAssignment()->value('user_id');
    }

    public function test_72_hours_after_the_visit_the_customer_is_called_and_a_happy_customer_closes_it(): void
    {
        $visit = $this->closedVisit();

        // Not before 72 hours.
        Carbon::setTestNow(now()->addHours(71));
        app(TicketFollowUpMonitor::class)->tick();
        $this->assertNull($this->details($visit)->aftercare_due_at);

        $agent = $this->dueFor($visit);
        $this->assertNotNull($this->details($visit)->aftercare_due_at);
        $this->assertSame($agent->id, $this->holder($visit), 'given to the follow-up agent');
        $this->assertTrue($visit->refresh()->is_closed, 'the visit stays closed while the call is pending');
        $this->assertTrue($agent->can('visitAftercare', $visit));

        $call = $this->workflow->recordVisitAftercare($visit, $agent, [
            'disposition' => 'satisfied_overall', 'action_required' => 'no_action', 'rating' => 5, 'service_working' => true,
            'installation_quality' => 'satisfactory', 'technician_conduct' => 'satisfactory', 'notes' => 'All good, thank you',
        ]);

        $this->assertSame(['connected', 'satisfied', 'confirmed'], [$call->call_outcome, $call->feedback, $call->outcome]);
        $ticket = $this->details($visit);
        $this->assertSame('satisfied', $ticket->aftercare_result);
        $this->assertNotNull($ticket->aftercare_done_at);
        $this->assertNull($this->holder($visit), 'nothing left in the queue');
        $this->assertTrue($visit->refresh()->is_closed);
        $this->assertFalse($agent->can('visitAftercare', $visit));

        // Only once.
        app(TicketFollowUpMonitor::class)->tick();
        $this->assertSame(1, TicketFollowUp::query()->where('outage_id', $visit->id)->where('kind', 'aftercare')->count());
    }

    public function test_the_same_issue_reopens_the_visit_and_a_new_issue_raises_a_ticket(): void
    {
        $same = $this->closedVisit('SF1001');
        $other = $this->closedVisit('SF1002');
        $agent = $this->dueFor($same);
        // One agent, two calls: the second is given once the first is done.
        $this->workflow->recordVisitAftercare($same, $agent, [
            'disposition' => 'dissatisfied_no_internet', 'action_required' => 'revisit', 'rating' => 2, 'service_working' => false, 'notes' => 'Red light again since yesterday',
        ]);
        $same->refresh();
        $this->assertFalse($same->is_closed);
        $this->assertSame(QueueType::VisitDispatch, $same->current_queue, 'back to dispatch for a team');
        $this->assertSame(1, $same->reopen_count);
        $this->assertSame('reopened', $this->details($same)->aftercare_result);

        app(TicketFollowUpMonitor::class)->tick();
        $call = $this->workflow->recordVisitAftercare($other, $agent, [
            'disposition' => 'vandalism_drop_cable', 'action_required' => 'raise_ticket', 'notes' => 'Drop cable cut by road works',
            'subcategory_id' => TicketSubcategory::query()->where('name', 'Drop Cable Cut')->value('id'), 'new_ticket_narration' => 'Drop cable cut by road works',
        ]);
        $new = Outage::query()->findOrFail($call->new_outage_id);
        $this->assertTrue($new->isCxTicket());
        $this->assertSame('SF1002', $this->details($new)->account_number);
        $this->assertSame('vandalism', $call->feedback);
        $this->assertSame('new_ticket', $this->details($other)->aftercare_result);
        $this->assertTrue($other->refresh()->is_closed, 'a different issue: the visit stays closed');
    }

    public function test_a_customer_not_reached_is_called_again_then_closed_as_unreachable(): void
    {
        $visit = $this->closedVisit();
        $agent = $this->dueFor($visit);

        $this->workflow->recordVisitAftercare($visit, $agent, ['disposition' => 'unreachable_no_answer']);
        $entry = $visit->openQueueEntries()->where('queue_type', QueueType::VisitAftercare->value)->firstOrFail();
        $this->assertTrue($entry->eligible_from->greaterThan(now()), 'offered again later');
        $this->assertNull($this->details($visit)->aftercare_done_at);

        // The customer asks to be called back at a time.
        Carbon::setTestNow(now()->addHours(5));
        // Due again: the scheduler's FIFO sweep (every minute) gives it to an agent.
        app(FifoAssignmentEngine::class)->process(QueueType::VisitAftercare);
        $this->assertSame($agent->id, $this->holder($visit));
        $this->workflow->recordVisitAftercare($visit, $agent, ['disposition' => 'unreachable_callback_scheduled', 'callback_at' => now()->addDay()->toDateTimeString()]);
        $this->assertSame(now()->addDay()->format('Y-m-d H:i'), $visit->openQueueEntries()->where('queue_type', QueueType::VisitAftercare->value)->firstOrFail()->eligible_from->format('Y-m-d H:i'));

        // Third attempt at the time agreed (the next day, with whoever is on shift), not reached: closed as unreachable.
        Carbon::setTestNow(now()->addDay()->addMinute());
        $nextDay = $this->makeUser('CX Follow-up – Site Visit Agent', 'CX');
        app(FifoAssignmentEngine::class)->process(QueueType::VisitAftercare);
        $this->assertSame($nextDay->id, $this->holder($visit));
        $this->workflow->recordVisitAftercare($visit, $nextDay, ['disposition' => 'unreachable_switched_off']);
        $this->assertSame('unreachable', $this->details($visit)->aftercare_result);
        $this->assertSame(3, $this->details($visit)->aftercare_attempts);
        $this->assertNull($visit->openQueueEntries()->where('queue_type', QueueType::VisitAftercare->value)->first());
    }

    public function test_visits_confirmed_before_the_feature_are_not_called(): void
    {
        $visit = $this->closedVisit();
        app(Settings::class)->set(['ticketing.aftercare_since' => now()->addHour()->toDateTimeString()]);

        $this->dueFor($visit);
        $this->assertNull($this->details($visit)->aftercare_due_at);
    }

    public function test_the_screen_and_the_reports(): void
    {
        $visit = $this->closedVisit();
        $agent = $this->dueFor($visit);

        $this->actingAs($agent);
        Livewire::test(ListSiteVisits::class)->set('activeTab', 'aftercare')->assertCanSeeTableRecords([$visit]);
        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])
            ->assertActionVisible(TestAction::make('visitAftercare'))
            ->callAction(TestAction::make('visitAftercare'), [
                'disposition' => 'dissatisfied_slow', 'action_required' => 'existing_ticket', 'rating' => 3, 'service_working' => true,
                'installation_quality' => 'satisfactory', 'technician_conduct' => 'unsatisfactory', 'notes' => 'Slow in the evenings',
            ])
            ->assertHasNoActionErrors();
        $this->assertSame('dissatisfied', $this->details($visit)->aftercare_result);

        $registry = app(ReportRegistry::class);
        $this->assertArrayHasKey('visit_aftercare', ReportRegistry::availableTo($agent));
        $calls = $registry->run('visit_aftercare', ['from' => '2026-10-01', 'to' => '2026-10-31'])->rowsArray();
        $this->assertCount(1, $calls);
        $this->assertSame(['Dissatisfied', 'Dissatisfied – Slow Speed', 'Existing Ticket Follow-Up', 3, 'Unsatisfactory'],
            [$calls[0]['feedback'], $calls[0]['disposition'], $calls[0]['action'], $calls[0]['rating'], $calls[0]['technician_conduct']]);

        $summary = collect($registry->run('visit_aftercare_summary', ['from' => '2026-10-01', 'to' => '2026-10-31'])->rowsArray())->keyBy(fn ($row) => $row['group'].'|'.$row['name']);
        $this->assertSame([1, 1, 0, 1, '0%', 3.0, '0%'], array_values(array_intersect_key($summary['All|All follow-up calls'],
            array_flip(['calls', 'reached', 'satisfied', 'dissatisfied', 'satisfied_pct', 'avg_rating', 'conduct_pct']))));
        $this->assertArrayHasKey('Technician|'.$visit->refresh()->technician?->name, $summary->all());
    }
}
