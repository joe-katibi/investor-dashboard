<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Mail\ScheduledReportMail;
use App\Models\EmailTemplate;
use App\Models\FinalReason;
use App\Models\Outage;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vertical;
use App\Services\Outages\OutageWorkflow;
use App\Services\Reports\FourGTicketReports;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ScheduledReportService;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\FourGTicketingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * 4G ticket reports (summary by department and issue, register, pending with whom, SLA breaches by
 * whom, vendors) and the 2-hour / daily / weekly / monthly e-mails built from them.
 */
class FourGTicketReportsTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private User $frontline;

    private User $tier2;

    private User $noc;

    private User $rf;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->seedReference();
        $this->seed(FourGTicketingSeeder::class);
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
        $this->frontline = $this->makeUser('CX Frontline', 'CX');
        $this->tier2 = $this->makeUser('CX Tier II', 'CX');
        $this->noc = $this->makeUser('4G NOC', 'MOBILITY');
        $this->rf = $this->makeUser('4G RF Team', 'MOBILITY');
        Auth::setUser($this->frontline);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function raise(string $issue, string $account, ?int $vertical = null): Outage
    {
        return $this->workflow->createTicket([
            'vertical_id' => $vertical ?? Vertical::idFor(Vertical::MOBILITY_4G),
            'subcategory_id' => TicketSubcategory::query()->where('name', $issue)->value('id'),
            'account_number' => $account, 'customer_name' => 'Customer '.$account, 'calling_number' => '0772000101', 'description' => 'Reported',
        ], $this->frontline);
    }

    /** Four 4G tickets — at Tier II, at the RF team with its vendor, closed, at the NOC — and one FTTH ticket. */
    private function scenario(): array
    {
        $vendor = Vendor::query()->create(['name' => 'Radio Vendor Ltd', 'code' => 'RADIO', 'vendor_type' => 'rf', 'email' => 'noc@radio.example', 'is_active' => true]);

        $atTier2 = $this->raise('Slow speeds', '4G0001');
        $withVendor = $this->raise('No service / no signal', '4G0002');
        $withVendor = $this->workflow->routeTicket($withVendor, $this->tier2, QueueType::Ticket4gNoc, 'No signal');
        $withVendor = $this->workflow->routeTicket($withVendor, $this->noc, QueueType::TicketRf, 'Sector down');
        $withVendor = $this->workflow->escalateTicketToVendor($withVendor, $this->rf, $vendor->id, 'Radio unit alarm');
        $closed = $this->raise('Router configuration', '4G0003');
        $closed = $this->workflow->closeTicket($closed, $this->tier2, ['final_reason_id' => FinalReason::query()->where('name', 'Resolved remotely by CX Tier II (4G)')->value('id')]);
        $atNoc = $this->workflow->routeTicket($this->raise('Intermittent connection', '4G0004'), $this->tier2, QueueType::Ticket4gNoc, 'Drops every hour');
        $fibre = $this->raise('Slow Speeds', 'SF0001', Vertical::idFor(Vertical::FTTH));

        return compact('atTier2', 'withVendor', 'closed', 'atNoc', 'fibre', 'vendor');
    }

    private function rows(string $key, array $filters = []): array
    {
        return app(ReportRegistry::class)->run($key, $filters + ['from' => '2026-10-01', 'to' => '2026-10-31'])->rowsArray();
    }

    public function test_the_summary_counts_each_ticket_once_by_department_and_issue(): void
    {
        $this->scenario();
        $result = app(ReportRegistry::class)->run('tkt4g_summary', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $rows = collect($result->rowsArray())->keyBy(fn (array $row) => $row['group'].'|'.$row['name']);

        $this->assertSame(4, $result->summary['Tickets'], 'the FTTH ticket is left out');
        $this->assertSame(['total' => 4, 'waiting' => 0, 'in_progress' => 2, 'vendor' => 1, 'callback' => 0, 'closed' => 1],
            array_intersect_key($rows['All|All 4G tickets'], array_flip(['total', 'waiting', 'in_progress', 'vendor', 'callback', 'closed'])));
        $this->assertSame(2, $rows['Department|CX Tier II']['total'], 'one open there, one closed there');
        $this->assertSame(1, $rows['Department|CX Tier II']['closed']);
        $this->assertSame(1, $rows['Department|RF Team']['vendor']);
        $this->assertSame(1, $rows['Department|4G NOC (customer tickets)']['in_progress']);
        $this->assertSame(1, $rows['Issue reported|No service / no signal']['total']);
        // Departments follow the flow: Tier II, NOC, then the teams.
        $departments = collect($result->rowsArray())->where('group', 'Department')->pluck('name')->all();
        $this->assertSame(['CX Tier II', '4G NOC (customer tickets)', 'RF Team'], $departments);
    }

    public function test_pending_with_whom_register_and_vendors(): void
    {
        $s = $this->scenario();

        $pending = collect($this->rows('tkt4g_pending'))->keyBy('ticket');
        $this->assertCount(3, $pending);
        $this->assertSame('Vendor: Radio Vendor Ltd', $pending[$s['withVendor']->ticket_number]['with']);
        $this->assertSame($this->rf->name, $pending[$s['withVendor']->ticket_number]['person']);
        $this->assertSame($this->noc->name, $pending[$s['atNoc']->ticket_number]['with']);
        $this->assertSame(['CX Tier II', '4G NOC (customer tickets)', 'RF Team'], $pending->pluck('department')->values()->all(), 'in the order of the flow');

        $register = collect($this->rows('tkt4g_register'))->keyBy('ticket');
        $this->assertCount(4, $register);
        $this->assertSame('Closed', $register[$s['closed']->ticket_number]['state']);
        $this->assertSame('CX Tier II', $register[$s['closed']->ticket_number]['department'], 'a closed ticket shows where it was closed');
        $this->assertSame('Radio Vendor Ltd', $register[$s['withVendor']->ticket_number]['vendor']);
        $this->assertCount(1, $this->rows('tkt4g_register', ['state' => 'closed']));

        $vendors = $this->rows('tkt4g_vendors');
        $this->assertSame(['vendor' => 'Radio Vendor Ltd', 'escalated' => 1, 'waiting' => 1, 'answered' => 0, 'closed' => 0],
            array_intersect_key($vendors[0], array_flip(['vendor', 'escalated', 'waiting', 'answered', 'closed'])));
    }

    public function test_sla_breaches_name_the_department_and_person(): void
    {
        $s = $this->scenario();

        // Past every SLA: the SLA engine records each breach with the desk and person holding the ticket.
        Carbon::setTestNow('2026-10-07 17:00:00');
        $this->artisan('oms:sla-check')->assertSuccessful();

        $breaches = collect($this->rows('tkt4g_breaches'))->where('kind', 'Ticket SLA')->keyBy('ticket');
        $this->assertArrayHasKey($s['withVendor']->ticket_number, $breaches->all());
        $this->assertSame('RF Team', $breaches[$s['withVendor']->ticket_number]['desk']);
        $this->assertSame($this->rf->name, $breaches[$s['withVendor']->ticket_number]['held_by']);
        $this->assertArrayNotHasKey($s['fibre']->ticket_number, $breaches->all(), 'FTTH tickets are not in the 4G report');
    }

    public function test_the_reports_are_offered_to_the_4g_desks_and_export(): void
    {
        $this->scenario();

        $this->assertArrayHasKey('tkt4g_summary', ReportRegistry::availableTo($this->noc));
        $this->assertArrayHasKey('tkt4g_breaches', ReportRegistry::availableTo($this->tier2));
        $this->assertArrayNotHasKey('tkt4g_summary', ReportRegistry::availableTo($this->frontline));

        foreach (array_keys(FourGTicketReports::REPORTS) as $key) {
            $file = app(ReportExporter::class)->writeXlsx(app(ReportRegistry::class)->run($key, ['from' => '2026-10-01', 'to' => '2026-10-31']));
            $this->assertFileExists($file);
            @unlink($file);
        }
    }

    public function test_the_four_emails_are_scheduled_and_sent_with_the_register(): void
    {
        $this->scenario();
        app(EmailTemplateSeeder::class)->fourGTickets();
        $templates = EmailTemplate::query()->where('code', 'like', 'ticket_4g_%')->get()->keyBy('code');

        $this->assertSame(['07:00', '09:00', '11:00', '13:00', '15:00', '17:00', '19:00'], $templates['ticket_4g_update']->daily_times);
        $this->assertSame(['07:00', '19:00'], $templates['ticket_4g_daily']->daily_times);
        $this->assertSame('0 7 * * 1', $templates['ticket_4g_weekly']->cron_expression);
        $this->assertSame('0 6 1 * *', $templates['ticket_4g_monthly']->cron_expression);

        $service = app(ScheduledReportService::class);
        $update = $service->render($templates['ticket_4g_update'], now());
        $this->assertStringContainsString('4G Tickets – 2-Hour Update', $update['html']);
        $this->assertStringContainsString('Vendor: Radio Vendor Ltd', $update['html']);
        $this->assertStringContainsString('RF Team', $update['html']);
        $this->assertSame(3, $update['data']['t4_open']);
        $this->assertSame(1, $update['data']['t4_with_vendor']);

        // The monthly summary of October, sent on 1 November at 06:00.
        $monthly = $service->render($templates['ticket_4g_monthly'], Carbon::parse('2026-11-01 06:00'));
        $this->assertSame('October 2026', $monthly['data']['t4_period_label']);
        $this->assertSame(4, $monthly['data']['t4_raised']);
        $this->assertSame(1, $monthly['data']['t4_closed']);

        // Due on Monday 07:00 and sent with the register attached.
        $weekly = $templates['ticket_4g_weekly'];
        $weekly->forceFill(['is_active' => true, 'to' => ['noc@example.com']])->save();
        $this->assertTrue($service->isDue($weekly->refresh(), Carbon::parse('2026-10-12 07:00')));
        $service->send($weekly, Carbon::parse('2026-10-12 07:00'));
        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail) => str_contains($mail->subjectLine, '4G Tickets Weekly Summary')
            && $mail->attachmentPath !== null);
    }
}
