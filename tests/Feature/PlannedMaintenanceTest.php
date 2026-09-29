<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Filament\Pages\MaintenanceSettings;
use App\Filament\Pages\RequestMaintenance;
use App\Filament\Resources\MaintenanceTemplates\MaintenanceTemplateResource;
use App\Filament\Resources\Outages\OutageResource;
use App\Mail\MaintenanceMail;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestSignature;
use App\Models\MaintenanceMailSend;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Models\Vertical;
use App\Services\Maintenance\CrfPdf;
use App\Services\Maintenance\MaintenanceScheduler;
use App\Services\Outages\OutageWorkflow;
use App\Services\Reports\ReportRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** Planned maintenance: Infrastructure request → NOC CRF → sign-off → field work → NOC verify → CX notify. */
class PlannedMaintenanceTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private OutageWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        $this->workflow = app(OutageWorkflow::class);
    }

    private function request(User $by, array $overrides = []): Outage
    {
        Auth::setUser($by);

        return $this->workflow->requestMaintenance(array_merge([
            'title' => 'Kabowa FDT 17 migration to Seguku',
            'vertical_id' => Vertical::query()->where('code', Vertical::FTTH)->value('id'),
            'location' => 'Kabowa',
            'customers_affected' => 40,
            'scheduled_start' => now()->addDays(2)->startOfHour(),
            'scheduled_end' => now()->addDays(2)->startOfHour()->addHours(4),
            'in_charge' => 'Fahad',
            'emergency_contact' => '+256787191167',
            'work_description' => 'Physical relocation of patch cable and re-provisioning of client devices.',
            'fallback_plan' => 'Re-align the ports to their slots on the old OLT.',
        ], $overrides), $by);
    }

    private function sendForSignoff(Outage $outage, User $noc, array $signatories): Outage
    {
        Auth::setUser($noc);

        return $this->workflow->sendCrfForSignoff($outage, $noc, [
            'customers_affected' => 55,
            'risk_tier' => 'high',
            'checklist' => ['customers_identified' => true],
            'signatories' => $signatories,
        ]);
    }

    /** The personal link from the sign-off e-mail sent to $email. */
    private function signLinkFor(string $email): string
    {
        $html = NotificationLog::query()->where('event', 'maintenance_sign_request')->where('recipient', $email)->latest('id')->firstOrFail()->meta['html'];
        preg_match('/href="([^"]+)"/', $html, $m);

        return html_entity_decode($m[1]);
    }

    private function approveAll(Outage $outage): void
    {
        foreach (ChangeRequest::query()->where('outage_id', $outage->id)->firstOrFail()->signatures as $signature) {
            $this->workflow->recordSignature($signature, ['decision' => 'approve', 'signed_name' => $signature->displayName(), 'signature_image' => self::PNG]);
        }
    }

    public function test_full_planned_maintenance_flow_from_request_to_cx_notification(): void
    {
        $infraLead = $this->makeUser('Infrastructure Team Leader', 'INFRA', attributes: ['availability_status' => 'offline']);
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $nocManager = $this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline', 'email' => 'noc.manager@example.com']);
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $crew = $this->makeTeam('Crew', 'INFRA', $team);
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $tech = $this->makeUser('Outage Technician', 'INFRA', [$crew]);
        $cx = $this->makeUser('CX Tier II', 'CX');

        // 1. Infrastructure raises the request → NOC queue.
        $outage = $this->request($infraLead);
        $this->assertTrue($outage->status->isCode(StatusCode::MaintenanceRequested));
        $this->assertSame('PLM', explode('-', $outage->ticket_number)[0]);
        $this->assertSame($noc->id, $outage->current_assignee_id);
        $this->assertFalse($noc->can('confirm', $outage), 'normal NOC confirm/reject does not apply');
        $this->assertSame(360, (int) $outage->start_time->diffInMinutes($outage->sla_due_at), 'SLA: 6 hours from the scheduled start');

        // 2. NOC confirms customers and sends the CRF for sign-off.
        $outage = $this->sendForSignoff($outage, $noc, [
            ['role' => 'NOC', 'user_id' => $nocManager->id],
            ['role' => 'Chief Technology Officer (CTO)', 'email' => 'cto@example.com'],
        ]);
        $this->assertTrue($outage->status->isCode(StatusCode::CrfPendingSignoff));
        $this->assertSame(55, $outage->customers_affected);
        $this->assertFalse($dispatcher->can('dispatch', $outage), 'no dispatch before the CRF is signed');
        $this->assertTrue(NotificationLog::query()->where('event', 'maintenance_crf_issued')->where('recipient', 'noc.manager@example.com')->exists(), 'stakeholders get the CRF notice');
        $this->assertSame(2, NotificationLog::query()->where('event', 'maintenance_sign_request')->count(), 'one personal sign e-mail per signatory');
        Mail::assertSent(MaintenanceMail::class, fn (MaintenanceMail $mail) => $mail->hasTo('cto@example.com') && count($mail->attachments()) >= 1);

        // 3. Signatories sign through their personal links (no login).
        $ctoLink = $this->signLinkFor('cto@example.com');
        $this->get($ctoLink)->assertOk()->assertSee('Kabowa FDT 17 migration to Seguku')->assertSee('Approve &amp; sign', false);
        $this->get($ctoLink.'x')->assertForbidden(); // tampered link

        $this->post($ctoLink, ['decision' => 'approve', 'signed_name' => 'Chief Tech', 'signature_image' => self::PNG])->assertRedirect();
        $this->get($ctoLink)->assertStatus(410); // single use
        $this->assertSame(ChangeRequest::PENDING_SIGNOFF, ChangeRequest::query()->first()->status);

        $this->post($this->signLinkFor('noc.manager@example.com'), ['decision' => 'approve', 'signed_name' => 'Nora', 'signature_image' => self::PNG])->assertRedirect();

        // 4. Fully signed → everyone gets the signed CRF → Infrastructure dispatch.
        $outage->refresh();
        $this->assertSame(ChangeRequest::APPROVED, $outage->changeRequest->status);
        $this->assertTrue($outage->status->isCode(StatusCode::AssignedToInfrastructure));
        $this->assertSame(QueueType::Infrastructure, $outage->current_queue);
        $this->assertSame($dispatcher->id, $outage->current_assignee_id);
        $this->assertTrue(NotificationLog::query()->where('event', 'maintenance_fully_signed')->where('recipient', 'cto@example.com')->exists());

        // 5. Dispatch → technician completes → NOC verifies all customers are up → CX.
        Auth::setUser($dispatcher);
        $outage = $this->workflow->dispatch($outage, ['team_id' => $team->id, 'sub_team_id' => $crew->id], $dispatcher);
        Auth::setUser($tech);
        $outage = $this->workflow->reportRestored($outage, $tech, 'Patch cables moved, ONUs re-provisioned');
        Auth::setUser($noc);

        try {
            $this->workflow->confirmRestoration($outage, $noc, null, []);
            $this->fail('A final status is required for maintenance');
        } catch (ValidationException) {
        }

        $outage = $this->workflow->confirmRestoration($outage, $noc, null, ['final_status' => 'successful', 'outcome_details' => '3 ONUs powered off — to track']);
        $this->assertTrue($outage->status->isCode(StatusCode::CxCustomerNotification));
        $this->assertSame(QueueType::CxNotify, $outage->current_queue);
        $this->assertSame($cx->id, $outage->current_assignee_id);

        // 6. CX tells customers → closed; completion e-mail goes out.
        Auth::setUser($cx);
        $outage = $this->workflow->notifyCustomersMaintenanceDone($outage, $cx, ['channels' => ['SMS'], 'note' => 'SMS sent to all affected clients']);
        $this->assertTrue($outage->status->isCode(StatusCode::Resolved));
        $this->assertTrue($outage->is_closed);
        $this->assertStringStartsWith('Successful', $outage->final_reason);
        $this->assertTrue(NotificationLog::query()->where('event', 'maintenance_completed')->exists());
    }

    public function test_changes_requested_goes_back_to_noc_and_reissue_resets_signatures(): void
    {
        $infraLead = $this->makeUser('Infrastructure Team Leader', 'INFRA', attributes: ['availability_status' => 'offline']);
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $outage = $this->sendForSignoff($this->request($infraLead), $noc, [
            ['role' => 'CE Manager', 'email' => 'ce@example.com'],
            ['role' => 'CTO', 'email' => 'cto@example.com'],
        ]);

        $link = $this->signLinkFor('ce@example.com');
        $this->post($link, ['decision' => 'changes', 'signed_name' => 'CE Manager'])->assertSessionHasErrors('comment');
        $this->post($link, ['decision' => 'changes', 'signed_name' => 'CE Manager', 'comment' => 'Move it to Sunday night'])->assertRedirect();

        $outage->refresh();
        $this->assertTrue($outage->status->isCode(StatusCode::MaintenanceRequested));
        $this->assertSame(ChangeRequest::CHANGES_REQUESTED, $outage->changeRequest->status);
        $this->assertSame(QueueType::NocReview, $outage->current_queue);
        $this->get($this->signLinkFor('cto@example.com'))->assertForbidden(); // other open links withdrawn

        // NOC updates and re-issues: new signatures, new links.
        $outage = $this->sendForSignoff($outage, $noc, [['role' => 'CE Manager', 'email' => 'ce@example.com'], ['role' => 'CTO', 'email' => 'cto@example.com']]);
        $this->assertTrue($outage->status->isCode(StatusCode::CrfPendingSignoff));
        $this->assertSame(2, $outage->changeRequest->signatures()->where('status', ChangeRequestSignature::PENDING)->count());
        $this->get($this->signLinkFor('cto@example.com'))->assertOk();
    }

    public function test_reminders_start_alert_and_reschedule(): void
    {
        $infraLead = $this->makeUser('Infrastructure Team Leader', 'INFRA', attributes: ['availability_status' => 'offline']);
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $start = now()->addHours(30)->startOfMinute();
        $outage = $this->sendForSignoff($this->request($infraLead, ['scheduled_start' => $start, 'scheduled_end' => $start->copy()->addHours(4)]), $noc, [['role' => 'CTO', 'email' => 'cto@example.com']]);
        $this->approveAll($outage);
        $scheduler = app(MaintenanceScheduler::class);
        $reminders = fn () => NotificationLog::query()->where('event', 'maintenance_reminder')->count();

        // 24h before: reminder sent once.
        $this->travelTo($start->copy()->subMinutes(1440)->addMinute());
        $scheduler->tick();
        $scheduler->tick();
        $this->assertGreaterThan(0, $sent24 = $reminders());
        $this->assertSame(1, MaintenanceMailSend::query()->where('slot', 'reminder:1440')->count());

        // Scheduler down for a while: a reminder whose moment passed long ago is skipped, not sent late.
        $this->travelTo($start->copy()->subMinutes(700));
        $scheduler->tick();
        $this->assertSame('skipped', MaintenanceMailSend::query()->where('slot', 'reminder:720')->value('status'));
        $this->assertSame($sent24, $reminders());

        // Reschedule: new window, SLA moved, fresh reminder set, everyone told.
        $newStart = $start->copy()->addDay();
        Auth::setUser($infraLead);
        $outage = $this->workflow->rescheduleMaintenance($outage, $infraLead, $newStart, $newStart->copy()->addHours(3), 'Vendor delayed');
        $this->assertTrue($outage->start_time->equalTo($newStart));
        $this->assertTrue($outage->sla_due_at->equalTo($newStart->copy()->addHours(6)));
        $this->assertSame(1, $outage->changeRequest->reschedule_count);
        $this->assertTrue(NotificationLog::query()->where('event', 'maintenance_rescheduled')->where('recipient', 'cto@example.com')->exists());

        $this->travelTo($newStart->copy()->subMinutes(1440)->addMinute());
        $scheduler->tick();
        $this->assertSame(2, MaintenanceMailSend::query()->where('slot', 'reminder:1440')->count(), 'new window, new reminder');

        // Window opens: "started" alert once.
        $this->travelTo($newStart->copy()->addMinute());
        $scheduler->tick();
        $scheduler->tick();
        $this->assertSame(1, NotificationLog::query()->where('event', 'maintenance_started')->where('recipient', 'cto@example.com')->count());
        $this->assertNotNull($outage->changeRequest->refresh()->started_notified_at);
    }

    public function test_sla_follows_the_configurable_policy(): void
    {
        SlaPolicy::query()->where('name', 'Planned Maintenance – window')->update(['resolution_minutes' => 480]);
        $outage = $this->request($this->makeUser('Infrastructure Team Leader', 'INFRA', attributes: ['availability_status' => 'offline']));

        $this->assertSame(480, (int) $outage->start_time->diffInMinutes($outage->sla_due_at));
    }

    public function test_crf_can_be_viewed_and_downloaded_and_is_in_the_reports(): void
    {
        $infraLead = $this->makeUser('Infrastructure Team Leader', 'INFRA', attributes: ['availability_status' => 'offline']);
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $outage = $this->sendForSignoff($this->request($infraLead), $noc, [['role' => 'CTO', 'email' => 'cto@example.com']]);
        $this->approveAll($outage);

        $this->assertStringStartsWith('%PDF', app(CrfPdf::class)->render($outage->changeRequest));

        $this->app['auth']->forgetGuards(); // earlier workflow calls set a user on the guard
        $this->get(route('crf.pdf', $outage))->assertRedirect(); // guests → login
        $this->actingAs($noc)->get(route('crf.pdf', $outage))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment;', $this->actingAs($noc)->get(route('crf.pdf', ['outage' => $outage, 'download' => 1]))->headers->get('Content-Disposition'));

        $rows = app(ReportRegistry::class)->run('planned_maintenance')->rowsArray();
        $this->assertSame($outage->ticket_number, $rows[0]['ticket']);
        $this->assertSame('Signed & approved', $rows[0]['crf_status']);
        $this->assertSame('1 / 1', $rows[0]['signed']);
    }

    public function test_only_infrastructure_requests_and_screens_render(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $infraLead = $this->makeUser('Infrastructure Team Leader', 'INFRA', attributes: ['availability_status' => 'offline']);
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $admin = $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);

        try {
            $this->request($agent);
            $this->fail('CX agents cannot request maintenance');
        } catch (AuthorizationException) {
        }

        try {
            Auth::setUser($agent);
            $this->workflow->create([
                'title' => 'x', 'outage_type_id' => OutageType::query()->where('code', 'planned')->value('id'),
                'vertical_id' => Vertical::query()->where('code', Vertical::FTTH)->value('id'), 'customers_affected' => 1,
            ], $agent);
            $this->fail('Planned maintenance cannot be reported as an outage');
        } catch (ValidationException) {
        }

        $this->actingAs($agent)->get(RequestMaintenance::getUrl())->assertForbidden();
        $this->travelTo(now()->setTime(17, 24, 45)); // a moment with seconds
        $page = $this->actingAs($infraLead)->get(RequestMaintenance::getUrl())->assertOk()->assertSee('Send request to NOC');
        // Browsers only accept times on the minute step from `min`; a min with seconds rejects every normal time.
        preg_match_all('/\bmin="([^"]*:[^"]*)"/', $page->getContent(), $mins); // date/time minimums only
        $this->assertNotEmpty($mins[1]);
        foreach ($mins[1] as $min) {
            $this->assertMatchesRegularExpression('/(T|\s)\d{2}:\d{2}(:00)?$/', $min, "min {$min} must be on a whole minute");
        }
        $this->travelBack();

        $outage = $this->request($infraLead);
        $this->actingAs($noc)->get(OutageResource::getUrl('view', ['record' => $outage]))->assertOk()->assertSee('Complete CRF &amp; send for sign-off', false);

        $this->actingAs($admin);
        $this->get(MaintenanceSettings::getUrl())->assertOk()->assertSee('Reminders before the start');
        $this->get(MaintenanceTemplateResource::getUrl())->assertOk()->assertSee('Maintenance notice – CRF for review');
    }
}
