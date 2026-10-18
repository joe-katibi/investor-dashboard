<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Filament\Resources\SiteVisits\Pages\ViewSiteVisit;
use App\Models\Outage;
use App\Models\OutageQueueEntry;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Someone who turns a ticket down (or lets it time out) is not offered it again; when everyone at the
 * desk has, it waits. After 15 minutes — or at once with "Return to queue — offer to everyone" — it is
 * offered to all of them again.
 */
class ReopenWaitingTicketsTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private User $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 09:00:00');
        $this->seedReference();
        Mail::fake();
        $this->dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        Auth::setUser($this->dispatcher);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A visit its only dispatcher turned down: it waits with nobody on it. */
    private function turnedDown(): Outage
    {
        $visit = app(OutageWorkflow::class)->createSiteVisit([
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'),
            'subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => 'SF1', 'customer_name' => 'SF1', 'calling_number' => '0772000010', 'address' => 'Kansanga',
        ], $this->dispatcher);
        $this->assertSame($this->dispatcher->id, $visit->current_assignee_id);

        app(FifoAssignmentEngine::class)->reject($visit->refresh()->currentAssignment, $this->dispatcher, 'Busy with another area');
        app(FifoAssignmentEngine::class)->process(QueueType::VisitDispatch);
        $this->assertNull($visit->refresh()->current_assignee_id, 'nobody else at the desk: it waits');

        return $visit;
    }

    public function test_a_ticket_everyone_turned_down_is_offered_again_after_fifteen_minutes(): void
    {
        $visit = $this->turnedDown();
        $engine = app(FifoAssignmentEngine::class);

        Carbon::setTestNow(now()->addMinutes(10));
        $this->assertSame(0, $engine->reopenWaiting());
        $this->assertNull($visit->refresh()->current_assignee_id);

        Carbon::setTestNow(now()->addMinutes(6));
        $this->assertSame(1, $engine->reopenWaiting());
        $this->assertSame($this->dispatcher->id, $visit->refresh()->current_assignee_id);
        $this->assertSame([], OutageQueueEntry::query()->where('outage_id', $visit->id)->where('queue_type', QueueType::VisitDispatch->value)->value('rejected_user_ids') ?? []);
        $this->assertDatabaseHas('outage_assignment_histories', ['outage_id' => $visit->id, 'action' => 'reopened']);
    }

    public function test_a_manager_returns_it_to_the_queue_at_once(): void
    {
        $visit = $this->turnedDown();
        $this->actingAs($this->makeUser('Ticketing Manager', 'MGMT'));

        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])
            ->assertActionVisible(TestAction::make('returnToQueue'))
            ->callAction(TestAction::make('returnToQueue'), ['reason' => 'Dispatcher back at the desk'])
            ->assertHasNoActionErrors();

        $this->assertSame($this->dispatcher->id, $visit->refresh()->current_assignee_id);

        // Not for someone who may not override FIFO on tickets, and not while someone has it.
        $this->actingAs($this->dispatcher);
        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])->assertActionHidden(TestAction::make('returnToQueue'));
    }
}
