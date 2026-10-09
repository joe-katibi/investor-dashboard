<?php

namespace Tests\Feature;

use App\Filament\Pages\TicketDashboard;
use App\Mail\ScheduledReportMail;
use App\Models\EmailTemplate;
use App\Models\FinalReason;
use App\Models\Outage;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Services\Outages\OutageWorkflow;
use App\Services\Reports\InsightReports;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ScheduledReportService;
use App\Support\Settings;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Management reports: the life cycle of a ticket step by step, final reasons with a seven-day
 * trend (report, dashboard and e-mail), reason for outage, OLT performance per technician and
 * dispatcher — and the scheduled summaries that go with them.
 */
class InsightReportsTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 10:00:00'); // a Wednesday
        $this->seedReference();
        Mail::fake();
        $this->workflow = app(OutageWorkflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function report(string $key, array $filters = []): array
    {
        return app(ReportRegistry::class)->run($key, $filters)->rowsArray();
    }

    /** An outage taken from the report to its resolution. */
    private function resolvedOutage(User $agent, User $noc, User $dispatcher, User $technician, int $teamId, string $reason, array $overrides = []): Outage
    {
        $outage = $this->reportOutage($agent, $overrides);
        Auth::setUser($noc);
        $outage = $this->workflow->confirmByNoc($outage, $noc, 'Alarm confirmed');
        $this->travel(10)->minutes();
        Auth::setUser($dispatcher);
        $outage = $this->workflow->dispatch($outage, ['team_id' => $teamId, 'technician_id' => $technician->id], $dispatcher);
        $this->travel(50)->minutes();
        Auth::setUser($technician);
        $outage = $this->workflow->reportRestored($this->withSitePhotos($outage, $technician), $technician, 'Spliced');
        Auth::setUser($noc);
        $outage = $this->workflow->confirmRestoration($outage, $noc);

        return $this->workflow->resolve($outage, $noc, $reason);
    }

    private function ticket(User $by, string $account = 'SF2577916'): Outage
    {
        Auth::setUser($by);

        return $this->workflow->createTicket([
            'subcategory_id' => TicketSubcategory::query()->where('name', 'Wrong bill / double charge')->value('id'),
            'account_number' => $account, 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001',
        ], $by);
    }

    private function close(Outage $ticket, User $by, string $reason): Outage
    {
        Auth::setUser($by);

        return $this->workflow->closeTicket($ticket, $by, [
            'final_reason_id' => FinalReason::query()->where('name', $reason)->value('id'), 'resolution_notes' => 'Done', 'customer_confirmed' => true,
        ]);
    }

    public function test_the_life_cycle_report_follows_a_ticket_from_raised_through_every_desk_to_closed(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $technician = $this->makeUser('Outage Technician', 'INFRA', [$team]);

        $outage = $this->resolvedOutage($agent, $noc, $dispatcher, $technician, $team->id, 'Fibre cut by contractor');
        $open = $this->reportOutage($agent);

        $this->actingAs($this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']));
        $rows = collect($this->report('ticket_lifecycle'));
        $steps = $rows->where('ticket', $outage->ticket_number)->values();

        $this->assertSame(['Raised', 'NOC Review', 'Infrastructure Dispatch', 'Technician', 'NOC Restore Confirmation', 'Closed'], $steps->pluck('stage')->all());
        $this->assertSame(range(0, 5), $steps->pluck('step')->all());
        $this->assertSame($agent->name, $steps[0]['with']);
        $this->assertSame($noc->name, $steps[1]['with']);
        $this->assertSame(20, $steps[1]['target']);
        $this->assertSame('Within target', $steps[1]['result']);
        $this->assertSame($dispatcher->name, $steps[2]['with']);
        $this->assertSame(10, $steps[2]['minutes']);
        $this->assertSame($technician->name, $steps[3]['with']);
        $this->assertSame(50, $steps[3]['minutes']);
        $this->assertNull($steps[3]['target'], 'the technician step has no desk target');
        $this->assertStringContainsString('Fibre cut by contractor', $steps[5]['note']);
        $this->assertSame($noc->name, $steps[5]['with']);

        // A ticket still open ends at the desk it is waiting at.
        $waiting = $rows->where('ticket', $open->ticket_number)->values();
        $this->assertSame(['Raised', 'NOC Review'], $waiting->pluck('stage')->all());
        $this->assertSame('Waiting', $waiting[1]['result']);
        $this->assertNull($waiting[1]['left']);

        $this->assertSame([], $this->report('ticket_lifecycle', ['modules' => ['ticket']]), 'only the modules asked for');
    }

    public function test_reasons_for_outage_are_counted_together_whatever_the_spelling(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $technician = $this->makeUser('Outage Technician', 'INFRA', [$team]);
        $olt = $this->makeOlt('Salaama');

        $this->resolvedOutage($agent, $noc, $dispatcher, $technician, $team->id, 'Fibre cut by contractor', ['olt_ids' => [$olt->id], 'customers_affected' => 40]);
        $this->resolvedOutage($agent, $noc, $dispatcher, $technician, $team->id, '  fibre cut  by Contractor ', ['olt_ids' => [$olt->id], 'customers_affected' => 60]);
        $this->resolvedOutage($agent, $noc, $dispatcher, $technician, $team->id, 'Power failure at the OLT');
        $rejected = $this->reportOutage($agent);
        Auth::setUser($noc);
        $this->workflow->rejectByNoc($rejected, $noc, 'Customer CPE fault');

        $this->actingAs($this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']));
        $rows = collect($this->report('outage_reasons'));
        $cut = $rows->first();

        $this->assertSame(2, $rows->count(), 'the rejected ticket is still open with CX and not counted yet');
        $this->assertSame('Fibre cut by contractor', $cut['reason']);
        $this->assertSame(2, $cut['outages']);
        $this->assertSame('66.7%', $cut['share']);
        $this->assertSame(100, $cut['customers']);
        $this->assertSame('Salaama (2)', $cut['olts']);
        $this->assertSame('Outage', $cut['kind']);
        $this->assertGreaterThanOrEqual(60, $cut['avg_duration']);
    }

    public function test_olt_performance_is_shown_per_technician_and_dispatcher_with_the_sla_result(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $technician = $this->makeUser('Outage Technician', 'INFRA', [$team]);
        $olt = $this->makeOlt('Salaama');

        $this->resolvedOutage($agent, $noc, $dispatcher, $technician, $team->id, 'Fibre cut', ['olt_ids' => [$olt->id], 'customers_affected' => 40, 'start_time' => now()]);
        $this->resolvedOutage($agent, $noc, $dispatcher, $technician, $team->id, 'Fibre cut', ['olt_ids' => [$olt->id], 'customers_affected' => 10, 'start_time' => now()]);
        $this->reportOutage($agent, ['olt_ids' => [$olt->id]]);

        $this->actingAs($this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']));
        $rows = collect($this->report('olt_performance'))->where('olt', 'Salaama');
        $worked = $rows->where('technician', $technician->name)->first();

        $this->assertSame($dispatcher->name, $worked['dispatcher']);
        $this->assertSame('Outages', $worked['module']);
        $this->assertSame(2, $worked['tickets']);
        $this->assertSame(2, $worked['closed']);
        $this->assertSame(2, $worked['met']);
        $this->assertSame('100%', $worked['compliance']);
        $this->assertSame(10, $worked['avg_dispatch']);
        $this->assertSame(60, $worked['avg_restore']);
        $this->assertSame(50, $worked['customers']);
        $this->assertSame(1, $rows->where('technician', 'Not assigned')->first()['tickets'], 'the outage still with NOC');
    }

    public function test_final_reasons_are_trended_over_seven_days_on_the_report_the_dashboard_and_by_mail(): void
    {
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $billing = $this->makeUser('Billing Agent', 'BILLING');
        app(Settings::class)->set(['fifo.respect_shift' => false]); // the agent works on all the days below

        Carbon::setTestNow('2026-09-29 10:00:00');   // the week before
        $this->close($this->ticket($frontline, 'SF1000001'), $billing, 'Payment allocated / reflected');
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->close($this->ticket($frontline, 'SF1000002'), $billing, 'Billing query resolved');
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->close($this->ticket($frontline, 'SF1000003'), $billing, 'Billing query resolved');
        $this->close($this->ticket($frontline, 'SF1000004'), $billing, 'Payment allocated / reflected');
        $this->ticket($frontline, 'SF1000005');

        $trend = app(InsightReports::class)->finalReasons(now());
        $this->assertSame('Thu 01 Oct', $trend['days'][0]);
        $this->assertSame('Wed 07 Oct', $trend['days'][6]);
        $this->assertSame(3, $trend['total']);
        $this->assertSame(1, $trend['previous']);

        $rows = collect($trend['rows'])->keyBy('reason');
        $this->assertSame([2, '+2', 1, 1, 0], [$rows['Billing query resolved']['total'], $rows['Billing query resolved']['change'], $rows['Billing query resolved']['d4'], $rows['Billing query resolved']['d6'], $rows['Billing query resolved']['previous']]);
        $this->assertSame([1, '0', 1], [$rows['Payment allocated / reflected']['total'], $rows['Payment allocated / reflected']['change'], $rows['Payment allocated / reflected']['previous']]);
        $this->assertSame('Billing query resolved', $trend['rows'][0]['reason'], 'most common first');

        // The report, for people who may view tickets.
        $manager = $this->makeUser('CX Manager', 'CX', attributes: ['availability_status' => 'offline']);
        $this->actingAs($manager);
        $report = app(ReportRegistry::class)->run('ticket_final_reasons');
        $this->assertSame('Wed 07 Oct', $report->columns['d6']);
        $this->assertSame('66.7%', collect($report->rowsArray())->firstWhere('reason', 'Billing query resolved')['share']);
        $this->assertArrayNotHasKey('ticket_final_reasons', ReportRegistry::availableTo($this->makeUser('IT Engineer', 'IT', attributes: ['availability_status' => 'offline'])));

        // The dashboard.
        Livewire::test(TicketDashboard::class)
            ->assertSee('Final reasons — last 7 days')
            ->assertSee('Billing query resolved')
            ->assertSee('3 closed in the last seven days, 1 in the seven days before');

        // The e-mails.
        Auth::logout();
        $this->seed(EmailTemplateSeeder::class);
        $service = app(ScheduledReportService::class);
        $template = EmailTemplate::query()->where('code', 'ticket_final_reason_report')->firstOrFail();
        $rendered = $service->render($template, now());

        $this->assertSame('Final Reason Report – 7 days to 07 Oct 2026', $rendered['subject']);
        $this->assertStringContainsString('Billing query resolved', $rendered['html']);
        $this->assertStringContainsString('3 closed in these seven days, 1 in the seven days before', $rendered['html']);
        $this->assertStringNotContainsString('{{', $rendered['html']);

        $template->update(['to' => ['cx@example.com']]);
        $service->send($template, now());
        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail) => $mail->hasTo('cx@example.com') && $mail->attachmentPath !== null);
    }

    public function test_the_scheduled_summaries_exist_with_their_times_and_render_completely(): void
    {
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $this->makeUser('Billing Agent', 'BILLING');
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->reportOutage($agent);
        $raised = $this->ticket($frontline);
        Auth::logout();

        $this->seed(EmailTemplateSeeder::class);
        $service = app(ScheduledReportService::class);
        $template = fn (string $code) => EmailTemplate::query()->where('code', $code)->firstOrFail();

        $this->assertSame(['cron', '0 7 * * 1'], [$template('outage_weekly_summary')->schedule_type, $template('outage_weekly_summary')->cron_expression]);
        $this->assertSame('0 7 1 * *', $template('outage_monthly_summary')->cron_expression);
        $this->assertSame(120, $template('ticket_two_hour_update')->interval_minutes);
        $this->assertSame(360, $template('ticket_six_hour_summary')->interval_minutes);
        $this->assertSame(['07:00', '19:00'], $template('ticket_executive_summary')->daily_times);
        $this->assertSame(120, $template('productivity_two_hourly')->interval_minutes);
        $this->assertSame('0 7 * * 1', $template('productivity_weekly')->cron_expression);
        $this->assertSame('0 7 1 * *', $template('productivity_monthly')->cron_expression);
        $this->assertFalse($template('ticket_two_hour_update')->is_active, 'inactive until recipients are set');

        // Last week runs from its Monday, last month from its 1st.
        $this->assertSame('2026-09-28 00:00:00', $service->periodStart($template('outage_weekly_summary'), now())->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 00:00:00', $service->periodStart($template('outage_monthly_summary'), now())->format('Y-m-d H:i:s'));

        foreach (['outage_weekly_summary', 'outage_monthly_summary', 'ticket_two_hour_update', 'ticket_six_hour_summary', 'ticket_executive_summary',
            'ticket_final_reason_report', 'productivity_two_hourly'] as $code) {
            $rendered = $service->render($template($code), now());
            $this->assertStringNotContainsString('{{', $rendered['html'], $code);
            $this->assertStringNotContainsString('{{', $rendered['subject'], $code);
        }

        // The two-hourly ticket e-mail lists what was raised.
        $html = $service->render($template('ticket_two_hour_update'), now())['html'];
        $this->assertStringContainsString($raised->ticket_number, $html);
        $this->assertStringContainsString('RAISED IN THE LAST 2 HOURS', $html);

        // Running the seeder again changes nothing an administrator set.
        $template('ticket_two_hour_update')->update(['subject' => 'Ours', 'is_active' => true]);
        $count = EmailTemplate::query()->count();
        $this->seed(EmailTemplateSeeder::class);
        $this->assertSame($count, EmailTemplate::query()->count());
        $this->assertSame('Ours', $template('ticket_two_hour_update')->subject);
    }
}
