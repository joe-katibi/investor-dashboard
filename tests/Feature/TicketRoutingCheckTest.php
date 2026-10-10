<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Filament\Resources\SiteVisits\Pages\ViewSiteVisit;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Support\OutageLink;
use App\Models\Outage;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Database\Seeders\TicketCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * The flow of a customer ticket, category by category: where it starts, who approves it and who
 * carries it out — and `oms:check-ticket-routing`, which proves the same on a live server without
 * leaving a ticket behind.
 *
 *   Technical Support, Wi-Fi Extender, Shifting / Relocation, Installation, General → CX Tier II
 *   Billing                → Billing
 *   Refund                 → Commercial / Sales Admin (approves) → Billing (pays)
 *   Credit / Compensation  → CX Tier II (vets) → CX Manager (approves) → Billing (applies)
 */
class TicketRoutingCheckTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    /** category code => [first desk, approval desk, fulfilment desk] */
    private const FLOW = [
        'TECH' => [QueueType::CxTier2, null, null],
        'EXT' => [QueueType::CxTier2, null, null],
        'SHIFT' => [QueueType::CxTier2, null, null],
        'INST' => [QueueType::CxTier2, null, null],
        'BILL' => [QueueType::Billing, null, null],
        'REFUND' => [QueueType::Commercial, QueueType::Commercial, QueueType::Billing],
        'CREDIT' => [QueueType::CxTier2, QueueType::CxManager, QueueType::Billing],
        'GEN' => [QueueType::CxTier2, null, null],
    ];

    /** @var array<string, User> desk value => the person working it */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Monday, mid-morning: inside the working day.
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->seedReference();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** One available person on every desk a customer ticket can reach. */
    private function staffEveryDesk(): void
    {
        // One person per desk takes every ticket of these tests: the limit on tickets held at once is out of the way.
        app(Settings::class)->set(['fifo.max_active_per_user' => 500]);

        $desks = collect(TicketCatalogSeeder::ROUTES)->flatMap(fn (array $targets, string $from) => [$from, ...$targets])
            ->merge(collect(self::FLOW)->flatten()->filter()->map(fn (QueueType $desk) => $desk->value))
            ->unique();

        foreach ($desks as $value) {
            $user = $this->makeUser('CX Tier II', 'CX');
            $user->syncRoles([]);
            $user->givePermissionTo(['ticket.view', QueueType::from($value)->defaultPermission(), 'ticket.work', 'ticket.approve']);
            $this->staff[$value] = $user;
        }
    }

    public function test_each_of_the_eight_categories_starts_is_approved_and_is_carried_out_at_its_own_desks(): void
    {
        $this->staffEveryDesk();
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $workflow = app(OutageWorkflow::class);

        $this->assertSame(array_keys(self::FLOW), TicketCategory::query()->orderBy('sort_order')->pluck('code')->all(), 'the eight categories, in their order');

        foreach (self::FLOW as $code => [$first, $approval, $fulfilment]) {
            foreach (TicketSubcategory::query()->whereHas('category', fn ($query) => $query->where('code', $code))->orderBy('sort_order')->get() as $subcategory) {
                $where = "{$code} / {$subcategory->name}";
                $this->assertSame([$first, $approval, $fulfilment], [$subcategory->first_desk, $subcategory->approval_desk, $subcategory->fulfilment_desk], $where);

                Auth::setUser($frontline);
                $outage = $workflow->createTicket([
                    'subcategory_id' => $subcategory->id, 'account_number' => 'SF'.$subcategory->id, 'customer_name' => 'Jane Customer',
                    'calling_number' => '0772000001', 'address' => 'Seguku', 'amount' => 50000,
                ], $frontline);

                // Raised: on the first desk, held by the person of that desk.
                $this->assertSame([$first, $first, $this->staff[$first->value]->id], [$this->desk($outage), $outage->current_queue, $outage->current_assignee_id], "{$where}: raised");

                if (! $approval) {
                    continue;
                }

                // Sent for approval where the first desk only vets the request.
                if ($first !== $approval) {
                    Auth::setUser($this->staff[$first->value]);
                    $outage = $workflow->routeTicket($outage, $this->staff[$first->value], $approval, 'Vetted: downtime confirmed');
                    $this->assertSame([$approval, $this->staff[$approval->value]->id], [$this->desk($outage), $outage->current_assignee_id], "{$where}: for approval");
                }

                // Approved: on to the desk that pays / applies it.
                Auth::setUser($this->staff[$approval->value]);
                $outage = $workflow->approveTicket($outage, $this->staff[$approval->value], 'Approved');
                $this->assertSame([$fulfilment, $fulfilment, $this->staff[$fulfilment->value]->id], [$this->desk($outage), $outage->current_queue, $outage->current_assignee_id], "{$where}: approved");
            }
        }
    }

    public function test_a_ticket_waiting_for_its_site_visit_shows_the_desk_and_the_person_the_visit_is_with(): void
    {
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $tier2 = $this->makeUser('CX Tier II', 'CX');
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $workflow = app(OutageWorkflow::class);

        // CX Agent / Frontline raises it → CX Tier II holds it.
        Auth::setUser($frontline);
        $ticket = $workflow->createTicket([
            'subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => 'SF123456', 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001', 'address' => 'Seguku',
        ], $frontline);
        $this->assertSame([QueueType::CxTier2, $tier2->id], [$ticket->current_queue, $ticket->current_assignee_id]);

        // CX Tier II books a site visit: the visit is a ticket of its own, at the Site Visit Dispatch desk, with the dispatcher.
        Auth::setUser($tier2);
        $visit = $workflow->raiseSiteVisit($ticket, $tier2, ['visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id')]);
        $this->assertSame([QueueType::VisitDispatch, $dispatcher->id], [$visit->current_queue, $visit->current_assignee_id]);

        // The customer ticket itself is at no desk — but its page says where the work is and who has it.
        $ticket->refresh();
        $this->assertNull($ticket->current_queue);
        $this->actingAs($tier2);
        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->assertSee(QueueType::VisitDispatch->getLabel())
            ->assertSee($dispatcher->name)
            ->assertSee("Site visit {$visit->ticket_number}")
            ->assertSee('this ticket waits for it and closes with it')
            ->assertDontSee('Waiting for the site visit')
            // …and a button leads to the visit, where the technician is assigned.
            ->assertActionVisible('openVisit')
            ->assertActionHasUrl('openVisit', OutageLink::url($visit))
            ->assertActionHasLabel('openVisit', "Open site visit {$visit->ticket_number}");

        // The visit's own page has the dispatcher's actions; the customer ticket's page has none of them and no such button once the visit is closed.
        $this->actingAs($dispatcher);
        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])->assertActionVisible('visitAssign')->assertActionHidden('openVisit');
    }

    public function test_the_routing_check_follows_every_category_and_leaves_nothing_behind(): void
    {
        $this->staffEveryDesk();
        $this->makeUser('CX Frontline', 'CX');
        $before = [Outage::query()->count(), Ticket::query()->count(), DB::table('notification_logs')->count(), DB::table('audit_logs')->count(), DB::table('outage_queue_entries')->count()];

        $exit = Artisan::call('oms:check-ticket-routing', ['--all' => true]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);

        foreach (TicketCategory::query()->pluck('name') as $name) {
            $this->assertStringContainsString($name, $output);
        }
        $this->assertStringContainsString('Refund request: raised → Commercial / Sales Admin', $output);
        $this->assertStringContainsString('approved → Billing', $output);
        $this->assertStringContainsString('sent for approval → CX Manager', $output);
        $this->assertStringContainsString('given to '.$this->staff['cx_tier2']->name, $output);
        $this->assertStringNotContainsString('✗', $output);
        $this->assertStringContainsString('Nothing was kept.', $output);

        $this->assertSame($before, [Outage::query()->count(), Ticket::query()->count(), DB::table('notification_logs')->count(), DB::table('audit_logs')->count(), DB::table('outage_queue_entries')->count()]);
    }

    public function test_the_routing_check_names_the_desk_nobody_works_and_why_a_ticket_is_given_to_nobody(): void
    {
        $this->staffEveryDesk();
        $this->makeUser('CX Frontline', 'CX');

        // Billing loses its only person; the CX Tier II person goes off shift while shifts count.
        $this->staff['billing']->forceFill(['is_active' => false])->save();
        app(Settings::class)->set(['fifo.respect_shift' => true]);
        $this->staff['cx_tier2']->shiftAssignments()->delete();

        $this->assertSame(1, Artisan::call('oms:check-ticket-routing'));
        $output = Artisan::output();

        $this->assertStringContainsString('raised → Billing, but nobody holds this desk (permission queue.work_billing)', $output);
        $this->assertStringContainsString('none of the 1 people of this desk can be given it right now', $output);
        $this->assertStringContainsString($this->staff['cx_tier2']->name.': Shift', $output);
        $this->assertStringContainsString('problem(s) found', $output);
        $this->assertSame(0, Outage::query()->count());
    }

    private function desk(Outage $outage): ?QueueType
    {
        return Ticket::query()->where('outage_id', $outage->id)->firstOrFail()->desk;
    }
}
