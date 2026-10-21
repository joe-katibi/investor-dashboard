<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Models\Outage;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * The issue decides the kind of visit: Inhouse Shifting is a Support visit (Site Visit Dispatch, Site Visit
 * Technicians) whatever type is picked; oms:fix-visit-types corrects open visits booked with another kind.
 */
class VisitTypeFromIssueTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private User $shiftingDispatcher;

    private User $siteDispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        $this->workflow = app(OutageWorkflow::class);
        $this->shiftingDispatcher = $this->makeUser('Shifting / Relocation Dispatcher', 'DISPATCH');
        $this->siteDispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
    }

    private function type(string $prefix): int
    {
        return (int) VisitType::query()->where('code_prefix', $prefix)->value('id');
    }

    private function issue(string $name): int
    {
        return (int) TicketSubcategory::query()->where('name', $name)->value('id');
    }

    private function visit(string $prefix, string $issue, string $account, User $by): Outage
    {
        Auth::setUser($by);

        return $this->workflow->createSiteVisit([
            'visit_type_id' => $this->type($prefix), 'subcategory_id' => $this->issue($issue),
            'account_number' => $account, 'customer_name' => $account, 'calling_number' => '0772000010', 'address' => 'Kansanga',
        ], $by);
    }

    /** As booked before the rule: a Shifting visit whose issue is Inhouse Shifting. */
    private function oldInhouseShifting(string $account): Outage
    {
        $visit = $this->visit('SHI', 'Shifting', $account, $this->shiftingDispatcher);
        Ticket::query()->where('outage_id', $visit->id)->update(['subcategory_id' => $this->issue('Inhouse Shifting')]);

        return $visit->refresh();
    }

    public function test_inhouse_shifting_is_booked_as_a_support_visit_whatever_type_is_picked(): void
    {
        $visit = $this->visit('SHI', 'Inhouse Shifting', 'SF1', $this->siteDispatcher);

        $this->assertStringStartsWith('SUP-', $visit->ticket_number);
        $this->assertSame($this->type('SUP'), (int) $visit->ticket->visit_type_id);
        $this->assertSame(QueueType::VisitDispatch, $visit->current_queue);
    }

    public function test_open_visits_booked_with_the_wrong_kind_are_corrected(): void
    {
        $shiftingTech = $this->makeUser('Shifting / Relocation Technician', 'DISPATCH');
        $this->makeUser('Site Visit Technician', 'DISPATCH');

        $waiting = $this->oldInhouseShifting('SF2');
        $withTech = $this->workflow->assignVisit($this->oldInhouseShifting('SF3'), $this->shiftingDispatcher, ['technician_id' => $shiftingTech->id]);
        $onSite = $this->workflow->assignVisit($this->oldInhouseShifting('SF4'), $this->shiftingDispatcher, ['technician_id' => $shiftingTech->id]);
        $onSite = $this->workflow->visitArrive($onSite, $shiftingTech);
        $this->assertSame(QueueType::ShiftingDispatch, $waiting->current_queue);

        $this->artisan('oms:fix-visit-types')->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertSame($this->type('SHI'), (int) $waiting->refresh()->ticket->visit_type_id, 'the preview changes nothing');

        $this->artisan('oms:fix-visit-types', ['--apply' => true])->assertSuccessful();

        // Waiting at Shifting Dispatch: now a Support visit at Site Visit Dispatch, with a site visit dispatcher.
        $waiting->refresh();
        $this->assertSame($this->type('SUP'), (int) $waiting->ticket->visit_type_id);
        $this->assertSame(QueueType::VisitDispatch, $waiting->current_queue);
        $this->assertSame($this->siteDispatcher->id, $waiting->current_assignee_id);

        // With a shifting technician not yet there: back to Site Visit Dispatch for a site visit technician.
        $this->assertSame(QueueType::VisitDispatch, $withTech->refresh()->current_queue);

        // Already at the customer: left with them to finish.
        $onSite->refresh();
        $this->assertSame($this->type('SUP'), (int) $onSite->ticket->visit_type_id);
        $this->assertSame(QueueType::VisitTechnician, $onSite->current_queue);
        $this->assertSame($shiftingTech->id, $onSite->current_assignee_id);

        $this->artisan('oms:fix-visit-types')->expectsOutputToContain('nothing to correct')->assertSuccessful();
    }
}
