<?php

namespace Tests\Feature;

use App\Events\DesktopAlert;
use App\Filament\Pages\MyWork;
use App\Filament\Support\OutageLink;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Desktop notifications: every in-app notification is also announced to the person's open OMS
 * pages (live over Reverb, or found by asking the server), which show it as a browser
 * notification — only to that person, and without customer details on the desktop.
 */
class DesktopNotificationsTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->seedReference();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_assignment_is_announced_to_the_assignee_only_on_their_own_channel(): void
    {
        Event::fake([DesktopAlert::class]);
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');

        $outage = $this->reportOutage($agent, ['title' => 'Kira FDT 4 down']);
        $this->assertSame($noc->id, $outage->current_assignee_id);

        Event::assertDispatched(DesktopAlert::class, function (DesktopAlert $event) use ($noc, $outage): bool {
            return $event->userId === $noc->id
                && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$noc->id
                && $event->broadcastAs() === 'desktop.alert'
                && $event->alert['title'] === "Ticket assigned to you: {$outage->ticket_number}"
                && $event->alert['body'] === "Ticket {$outage->ticket_number} — click to open it in OMS."
                && $event->alert['url'] === OutageLink::url($outage)
                && $event->alert['ticket'] === $outage->ticket_number
                && $event->alert['severity'] === 'info';
        });
        Event::assertNotDispatched(DesktopAlert::class, fn (DesktopAlert $event) => $event->userId === $agent->id);

        // The same id as the notification in the bell, so a page that hears it twice shows it once.
        $stored = $noc->notifications()->latest()->first();
        Event::assertDispatched(DesktopAlert::class, fn (DesktopAlert $event) => $event->alert['id'] === $stored->id);
    }

    public function test_what_is_shown_on_the_desktop_carries_no_customer_details_and_marks_breaches_as_critical(): void
    {
        $alert = DesktopAlert::describe('abc', 'SLA BREACHED: TKT-000007', 'sla_breach', 7, 'TKT-000007', "TKT-000007 — Wrong bill — Jane Customer (SF2577916)\nCustomer: Jane Customer | Tel: 0772000001");

        $this->assertSame('critical', $alert['severity']);
        $this->assertSame('Ticket TKT-000007 — click to open it in OMS.', $alert['body']);
        $this->assertStringNotContainsString('Jane', json_encode($alert));
        $this->assertStringNotContainsString('0772000001', json_encode($alert));

        // A notification that is not about a ticket shows its own text, shortened and without markup.
        $plain = DesktopAlert::describe('def', '3 tickets ready for credit', 'ticket_credit_ready', null, null, 'Pending more than 9 SLA hours:<br />TKT-1, TKT-2');
        $this->assertSame(['Pending more than 9 SLA hours: TKT-1, TKT-2', null, 'info'], [$plain['body'], $plain['url'], $plain['severity']]);
    }

    public function test_an_open_page_can_ask_for_what_is_new_and_gets_only_its_own_unread_notifications(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $other = $this->makeUser('NOC Tier II', 'NOC', attributes: ['availability_status' => 'offline']);
        // As the browser sends it: the moment the page was loaded, in UTC.
        $loaded = now()->utc()->format('Y-m-d\TH:i:s.v\Z');

        $this->getJson(route('desktop-alerts'))->assertUnauthorized();

        // Nothing yet.
        $this->actingAs($noc);
        $this->getJson(route('desktop-alerts', ['after' => $loaded]))->assertOk()->assertExactJson(['alerts' => [], 'now' => now()->toIso8601String()]);

        // A ticket is assigned a little later; a bell-only notification too; and one for somebody else.
        Carbon::setTestNow('2026-10-05 10:00:30');
        $outage = $this->reportOutage($agent, ['title' => 'Kira FDT 4 down']);
        $noc->notifyNow(Notification::make()->title('3 tickets ready for credit')->body('TKT-1, TKT-2, TKT-3')->toDatabase());
        $other->notifyNow(Notification::make()->title('Not for the NOC user')->toDatabase());

        Auth::setUser($noc);
        Carbon::setTestNow('2026-10-05 10:01:00');
        $answer = $this->actingAs($noc)->getJson(route('desktop-alerts', ['after' => $loaded]))->assertOk();

        $this->assertSame(now()->toIso8601String(), $answer->json('now'));
        $alerts = collect($answer->json('alerts'))->keyBy('title');
        // The assignment, the "assigned to NOC" notice of the notification rules, and the bell-only one — oldest first.
        $this->assertSame(
            ["Ticket assigned to you: {$outage->ticket_number}", "Assigned to NOC for review: {$outage->ticket_number}", '3 tickets ready for credit'],
            $alerts->keys()->all(),
        );
        $this->assertArrayNotHasKey('Not for the NOC user', $alerts->all());
        $this->assertSame(OutageLink::url($outage), $alerts["Ticket assigned to you: {$outage->ticket_number}"]['url']);
        $this->assertSame('TKT-1, TKT-2, TKT-3', $alerts['3 tickets ready for credit']['body']);
        $this->assertSame($noc->notifications()->pluck('id')->sort()->values()->all(), $alerts->pluck('id')->sort()->values()->all());

        // Asking again from the time of the answer brings nothing twice; a read notification is not announced.
        $this->getJson(route('desktop-alerts', ['after' => $answer->json('now')]))->assertOk()->assertJsonCount(0, 'alerts');
        $noc->unreadNotifications()->update(['read_at' => now()]);
        $this->getJson(route('desktop-alerts', ['after' => $loaded]))->assertOk()->assertJsonCount(0, 'alerts');

        // A page that slept for hours, or sends nonsense, never digs up old notifications.
        $this->getJson(route('desktop-alerts', ['after' => '2020-01-01T00:00:00Z']))->assertOk();
        $this->getJson(route('desktop-alerts', ['after' => 'not a date']))->assertOk()->assertJsonCount(0, 'alerts');

        // Someone deactivated is signed out here too.
        $noc->forceFill(['is_active' => false])->save();
        $this->get(route('desktop-alerts'))->assertRedirect();
    }

    public function test_signed_in_pages_carry_the_prompt_and_the_listener_and_the_sign_in_page_does_not(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('oms-desktop', false);

        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $this->actingAs($noc);

        $this->get(MyWork::getUrl())->assertOk()
            ->assertSee('id="oms-desktop"', false)
            ->assertSee('data-user-id="'.$noc->id.'"', false)
            ->assertSee('data-poll-url="'.route('desktop-alerts').'"', false)
            ->assertSee('Enable notifications')
            ->assertSee('.desktop.alert', false)
            ->assertSee('App.Models.User.', false);
    }
}
