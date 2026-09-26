<?php

namespace Tests\Feature;

use App\Filament\Pages\AlertEmails;
use App\Mail\OutageNotificationMail;
use App\Models\NotificationLog;
use App\Models\NotificationRule;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** In-app + e-mail alerts for the ticket lifecycle, including the configurable alert list. */
class AlertEmailsTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        app(Settings::class)->set(['alerts.emails' => ['noc-alerts@example.com', 'ops@example.com']]);
    }

    private function mailedTo(string $email, string $event): bool
    {
        return NotificationLog::query()->where('channel', 'mail')->where('recipient', $email)->where('event', $event)->where('status', 'sent')->exists();
    }

    public function test_lifecycle_events_email_the_alert_list_and_assignees(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        // Offline so FIFO does not pick them; they still receive role-based alerts.
        $lead = $this->makeUser('NOC Team Leader', 'NOC', attributes: ['email' => 'noc.lead@example.com', 'availability_status' => 'offline']);
        $noc = $this->makeUser('NOC Tier II', 'NOC', attributes: ['email' => 'noc.agent@example.com']);
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $crew = $this->makeTeam('Crew', 'INFRA', $team);
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team], ['email' => 'dispatcher@example.com']);
        $tech = $this->makeUser('Outage Technician', 'INFRA', [$crew], ['email' => 'tech@example.com']);
        $workflow = app(OutageWorkflow::class);

        // Created → NOC assignment
        $outage = $this->reportOutage($agent);
        $this->assertTrue($this->mailedTo('noc-alerts@example.com', 'outage_created'));
        $this->assertTrue($this->mailedTo('ops@example.com', 'noc_escalation'));
        $this->assertTrue($this->mailedTo('noc.agent@example.com', 'assigned'), 'NOC assignee is e-mailed');

        // NOC confirm → assigned to Infrastructure
        Auth::setUser($noc);
        $outage = $workflow->confirmByNoc($outage, $noc);
        $this->assertTrue($this->mailedTo('noc-alerts@example.com', 'infrastructure_escalation'));
        $this->assertTrue($this->mailedTo('dispatcher@example.com', 'assigned'));

        // Dispatch → technician, update, restoration reported ("closed by technician"), resolved
        Auth::setUser($dispatcher);
        $outage = $workflow->dispatch($outage, ['team_id' => $team->id, 'sub_team_id' => $crew->id], $dispatcher);
        Auth::setUser($tech);
        $workflow->addUpdate($outage, $tech, 'Crew on site');
        $outage = $workflow->reportRestored($outage, $tech, 'Spliced and restored');
        Auth::setUser($noc);
        $outage = $workflow->confirmRestoration($outage, $noc);
        $workflow->resolve($outage, $noc, 'Fibre cut');

        foreach (['dispatched', 'update_posted', 'restore_reported', 'resolved'] as $event) {
            $this->assertTrue($this->mailedTo('noc-alerts@example.com', $event), "alert list gets {$event}");
        }
        $this->assertTrue($this->mailedTo('tech@example.com', 'assigned'));

        // Role-based rules deliver the same events in-app and by e-mail (NOC Team Leader here).
        foreach (['outage_created', 'noc_escalation', 'restore_reported'] as $event) {
            $this->assertTrue(NotificationLog::query()->where('channel', 'database')->where('recipient_user_id', $lead->id)->where('event', $event)->exists(), "in-app {$event}");
            $this->assertTrue($this->mailedTo('noc.lead@example.com', $event), "e-mail {$event}");
        }
        Mail::assertSent(OutageNotificationMail::class);
    }

    public function test_one_email_per_address_per_event_even_when_rules_overlap(): void
    {
        app(Settings::class)->set(['alerts.emails' => ['dup@example.com']]);
        NotificationRule::query()->create([
            'name' => 'Overlap', 'event' => 'outage_created', 'channels' => ['mail'], 'recipient_emails' => ['DUP@example.com'], 'is_active' => true,
        ]);
        $agent = $this->makeUser('CX Agent', 'CX');

        $this->reportOutage($agent);

        $this->assertSame(1, NotificationLog::query()->where('recipient', 'dup@example.com')->where('event', 'outage_created')->count());
    }

    public function test_unselected_events_and_disabled_assignee_mail_are_respected(): void
    {
        app(Settings::class)->set(['alerts.events' => ['resolved'], 'alerts.email_assignee' => false]);
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->makeUser('NOC Tier I', 'NOC', attributes: ['email' => 'noc1@example.com']);

        $this->reportOutage($agent);

        $this->assertFalse($this->mailedTo('noc-alerts@example.com', 'outage_created'));
        $this->assertFalse(NotificationLog::query()->where('recipient', 'noc1@example.com')->where('channel', 'mail')->where('event', 'assigned')->exists());
        $this->assertTrue(NotificationLog::query()->where('channel', 'database')->where('event', 'assigned')->exists(), 'in-app assignment alert still sent');
    }

    public function test_alert_settings_page_is_permission_protected(): void
    {
        $this->actingAs($this->makeUser('CX Agent', 'CX'))->get(AlertEmails::getUrl())->assertForbidden();
        $this->actingAs($this->makeUser('NOC Manager', 'NOC'))->get(AlertEmails::getUrl())->assertOk()->assertSee('Alert e-mail addresses')->assertSee('Alert phone numbers');
    }
}
