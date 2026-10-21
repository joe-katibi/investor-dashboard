<?php

namespace Tests\Feature;

use App\Filament\Pages\TicketDashboard;
use App\Models\TicketSubcategory;
use App\Models\Vertical;
use App\Services\Outages\OutageWorkflow;
use App\Services\Tickets\TicketMetrics;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** The live Pending / SLA page for management: a link with a secret, no sign-in, figures only. */
class LiveTicketSummaryTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    public function test_management_opens_the_live_summary_from_its_link_without_customer_details(): void
    {
        $this->seedReference();
        Mail::fake();
        $agent = $this->makeUser('CX Frontline', 'CX');
        Auth::setUser($agent);
        app(OutageWorkflow::class)->createTicket(['subcategory_id' => TicketSubcategory::query()->where('name', 'Slow Speeds')->value('id'),
            'account_number' => 'SF2659301', 'customer_name' => 'Jane Nakato', 'calling_number' => '0772000001', 'description' => 'Slow'], $agent);
        $outage = $this->reportOutage($this->makeUser('CX Agent', 'CX'), ['title' => 'Kira FDT 4 down']);
        Auth::logout();

        // Off until someone switches it on.
        $this->get('/live/tickets/anything')->assertNotFound();

        // Switched on from the Customer Care dashboard.
        $this->actingAs($this->makeUser('Super Admin', 'MGMT'));
        Livewire::test(TicketDashboard::class)
            ->assertActionVisible(TestAction::make('liveLink'))
            ->callAction(TestAction::make('liveLink'), ['enabled' => true, 'renew' => false])
            ->assertHasNoActionErrors();
        $token = (string) app(Settings::class)->get('live.tickets_token');
        $this->assertSame(48, strlen($token));
        Auth::logout();

        // Without signing in: the summary, never a customer's name or account.
        $this->app['auth']->forgetGuards();
        $this->get("/live/tickets/{$token}")->assertOk()
            ->assertSee('Executive live summary')
            // FTTH outages (the open one listed), 4G outages, customer tickets & visits, 4G tickets.
            ->assertSee('FTTH outages')->assertSee($outage->ticket_number)->assertSee('Kira FDT 4 down')
            ->assertSee('4G outages')->assertSee('Sites down')
            ->assertSeeInOrder(['Customer tickets (FTTH)', 'SLA per department', 'Site visits', 'SLA per department'])
            ->assertSee('SLA per region')->assertSee('Closed today')
            ->assertSee('4G tickets')->assertSee('Pending — with whom', false)
            ->assertDontSee('Jane Nakato')->assertDontSee('SF2659301')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/live/tickets/'.str_repeat('x', 48))->assertNotFound();

        // Customer tickets and site visits are counted apart: the one customer ticket is not a visit.
        $ftth = [Vertical::idFor(Vertical::FTTH)];
        $this->assertSame(1, app(TicketMetrics::class)->snapshot(now(), 3, ['kind' => 'ticket', 'vertical_ids' => $ftth])['counts']['pending']);
        $this->assertSame(0, app(TicketMetrics::class)->snapshot(now(), 3, ['kind' => 'visit', 'vertical_ids' => $ftth])['counts']['pending']);

        // A new link: the old one stops working.
        $this->actingAs($this->makeUser('Super Admin', 'MGMT'));
        Livewire::test(TicketDashboard::class)->callAction(TestAction::make('liveLink'), ['enabled' => true, 'renew' => true]);
        $new = (string) app(Settings::class)->get('live.tickets_token');
        $this->assertNotSame($token, $new);
        $this->get("/live/tickets/{$token}")->assertNotFound();
        $this->get("/live/tickets/{$new}")->assertOk();

        // Switched off.
        Livewire::test(TicketDashboard::class)->callAction(TestAction::make('liveLink'), ['enabled' => false]);
        $this->get("/live/tickets/{$new}")->assertNotFound();

        // Not for people who do not manage the e-mails.
        $this->actingAs($this->makeUser('CX Tier II', 'CX'));
        Livewire::test(TicketDashboard::class)->assertActionHidden(TestAction::make('liveLink'));
    }
}
