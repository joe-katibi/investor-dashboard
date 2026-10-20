<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Filament\Pages\Reports;
use App\Mail\ScheduledReportMail;
use App\Models\EmailTemplate;
use App\Models\FinalReason;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use App\Services\Reports\ProductivityReport;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ScheduledReportService;
use App\Support\Settings;
use Database\Seeders\ProductivitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** Staff productivity across outages, 4G outages, customer tickets and site visits, per person and period. */
class ProductivityReportTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
        app(Settings::class)->set(['ticketing.confirm_closure' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> person|module => row */
    private function rows(string $group = 'period', array $filters = []): array
    {
        return collect(app(ProductivityReport::class)->rows(Carbon::parse('2026-10-05 00:00'), Carbon::parse('2026-10-05 23:59:59'), $group,
            $filters['modules'] ?? array_keys(ProductivityReport::MODULES), $filters['user_ids'] ?? []))
            ->keyBy(fn (array $row) => ($group === 'period' ? '' : $row['period'].'|').$row['person'].'|'.$row['module'])->all();
    }

    public function test_counts_what_each_person_did_in_every_module(): void
    {
        // FTTH outage: support raises it, the NOC confirms (a FIFO step), the NOC Manager later resolves.
        $support = $this->makeUser('CX Agent', 'CX');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $outage = $this->reportOutage($support);
        Carbon::setTestNow('2026-10-05 09:20:00');
        $this->workflow->confirmByNoc($outage, $noc, 'Confirmed on the OLT');

        // 4G: the NOC raises an eNodeB ticket and escalates it.
        $noc4g = $this->makeUser('4G NOC', 'MOBILITY');
        Auth::setUser($noc4g);
        $mobility = $this->workflow->createMobility([
            'outage_type_id' => OutageType::query()->where('code', 'mobility_enodeb')->value('id'), 'title' => 'Site down',
        ], $noc4g);
        $this->workflow->escalateMobility($mobility, $noc4g, 'Major site');

        // Customer ticket: frontline raises it, Tier II escalates to Billing, Billing closes it.
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $tier2 = $this->makeUser('CX Tier II', 'CX');
        $billing = $this->makeUser('Billing Agent', 'BILLING');
        Auth::setUser($frontline);
        $ticket = $this->workflow->createTicket([
            'subcategory_id' => TicketSubcategory::query()->where('name', 'Slow Speeds')->value('id'), 'account_number' => 'SF1', 'calling_number' => '0772000001',
        ], $frontline);
        Carbon::setTestNow('2026-10-05 10:00:00');
        $ticket = $this->workflow->routeTicket($ticket, $tier2, QueueType::Billing, 'Billing issue really');
        Carbon::setTestNow('2026-10-05 10:30:00');
        $this->workflow->closeTicket($ticket, $billing, ['final_reason_id' => FinalReason::query()->where('name', 'Billing query resolved')->value('id')]);

        // Site visit: Tier II books it, the dispatcher assigns it, the technician completes it after 45 minutes on site.
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH');
        Auth::setUser($tier2);
        $visit = $this->workflow->createSiteVisit([
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'), 'account_number' => 'SF2', 'calling_number' => '0772000002',
        ], $tier2);
        $visit = $this->workflow->assignVisit($visit, $dispatcher, ['technician_id' => $technician->id]);
        Carbon::setTestNow('2026-10-05 11:00:00');
        $visit = $this->workflow->visitArrive($visit, $technician);
        Carbon::setTestNow('2026-10-05 11:45:00');
        Storage::disk('local')->put('outage-attachments/g.jpg', 'x');
        Storage::disk('local')->put('outage-attachments/s.jpg', 'x');
        $visit = $this->workflow->completeVisit($visit, $technician, [
            'final_reason_id' => FinalReason::query()->where('name', 'Splicing done at the FAT')->value('id'), 'resolution_notes' => 'Respliced', 'fat_number' => 'FAT-1',
            'geo_photo' => 'outage-attachments/g.jpg', 'speed_photo' => 'outage-attachments/s.jpg',
        ]);
        $this->workflow->verifyVisit($visit, $dispatcher);

        $rows = $this->rows();
        $row = fn (User $user, string $module) => $rows[$user->name.'|'.ProductivityReport::MODULES[$module]] ?? $this->fail("No row for {$user->name} / {$module}");

        // Who raised what.
        $this->assertSame(1, $row($support, 'outage')['created']);
        $this->assertSame(1, $row($noc4g, 'mobility')['created']);
        $this->assertSame(1, $row($frontline, 'ticket')['created']);
        $this->assertSame(1, $row($tier2, 'visit')['created']);

        // NOC: FIFO step done (20 minutes after assignment), one ticket worked.
        $this->assertSame(1, $row($noc, 'outage')['steps']);
        $this->assertSame(1, $row($noc, 'outage')['worked']);
        $this->assertSame(20, $row($noc, 'outage')['avg_handle']);

        // 4G NOC escalation; Tier II escalation to Billing (100% of the tickets they worked).
        $this->assertSame(1, $row($noc4g, 'mobility')['escalated']);
        $this->assertSame(1, $row($tier2, 'ticket')['escalated']);
        $this->assertSame('100%', $row($tier2, 'ticket')['escalation_rate']);

        // Billing closed what it worked.
        $this->assertSame(1, $row($billing, 'ticket')['closed']);
        $this->assertSame('100%', $row($billing, 'ticket')['close_rate']);
        $this->assertSame(1.2, $row($billing, 'ticket')['avg_close'], 'open 09:20 → 10:30');

        // Technician: one visit completed, 45 minutes on site; the dispatcher closed it.
        $this->assertSame(1, $row($technician, 'visit')['field']);
        $this->assertSame(0.8, $row($technician, 'visit')['on_site_hours']);
        $this->assertSame(1, $row($dispatcher, 'visit')['closed']);

        // Tier II worked in two modules: an "All modules" row adds them up.
        $this->assertSame(1, $rows[$tier2->name.'|All modules']['escalated']);
        $this->assertSame(1, $rows[$tier2->name.'|All modules']['created']);

        // Hourly: the frontline ticket is in the 09:00 hour, the billing closure in the 10:00 hour.
        $hourly = $this->rows('hour');
        $this->assertSame(1, $hourly['Mon 05 Oct 09:00|'.$frontline->name.'|Customer tickets']['created']);
        $this->assertSame(1, $hourly['Mon 05 Oct 10:00|'.$billing->name.'|Customer tickets']['closed']);

        // Filters: one module, one person.
        $only = $this->rows('period', ['modules' => ['visit'], 'user_ids' => [$technician->id]]);
        $this->assertSame([$technician->name.'|Site visits'], array_keys($only));

        // Weekly and monthly groupings label their buckets.
        $this->assertStringStartsWith('Week of 05 Oct 2026', collect($this->rows('week'))->first()['period']);
        $this->assertSame('October 2026', collect($this->rows('month'))->first()['period']);
    }

    public function test_report_page_e_mails_and_module_access(): void
    {
        $tier2 = $this->makeUser('CX Tier II', 'CX');
        Auth::setUser($tier2);
        $this->workflow->createTicket([
            'subcategory_id' => TicketSubcategory::query()->where('name', 'Slow Speeds')->value('id'), 'account_number' => 'SF1', 'calling_number' => '0772000001',
        ], $tier2);
        $this->reportOutage($this->makeUser('NOC Tier I', 'NOC'));

        // A Customer Care manager sees only the ticket modules.
        $manager = $this->makeUser('Ticketing Manager', 'CX');
        $this->actingAs($manager);
        $this->assertArrayHasKey(ProductivityReport::KEY, ReportRegistry::availableTo($manager));
        // (…and, as a manager who may override FIFO, the custom workflows.)
        $this->assertSame(['ticket', 'visit', 'custom'], app(ProductivityReport::class)->modules());
        $report = app(ReportRegistry::class)->run(ProductivityReport::KEY, ['from' => '2026-10-05', 'to' => '2026-10-05', 'group' => 'day']);
        $this->assertSame(['Customer tickets'], collect($report->rowsArray())->pluck('module')->unique()->values()->all());

        Livewire::test(Reports::class)
            ->set('data.report', ProductivityReport::KEY)
            ->assertSee('Group by')
            ->set('data.group', 'hour')
            ->assertSee('Staff Productivity (all modules)')
            ->assertSee($tier2->name);

        // The scheduler sees every module.
        Auth::logout();
        $this->seed(ProductivitySeeder::class);
        // Hourly, daily, weekly, monthly — and the two-hourly one that comes with the management summaries.
        $this->assertSame(5, EmailTemplate::query()->where('report_type', 'like', 'productivity%')->count());

        Carbon::setTestNow('2026-10-05 19:00:00');
        $template = EmailTemplate::query()->where('code', 'productivity_daily')->firstOrFail();
        $rendered = app(ScheduledReportService::class)->render($template, now());
        $this->assertStringContainsString('Mon 05 Oct 2026 (to 19:00)', $rendered['subject']);
        // The e-mail is HTML: a name like "O'Keefe" is in it as O&#039;Keefe.
        $this->assertStringContainsString(e($tier2->name), $rendered['html']);
        $this->assertStringContainsString('Outages (FTTH / core / system)', $rendered['html']);
        $this->assertStringNotContainsString('{{', $rendered['html']);

        $template->update(['to' => ['managers@example.com']]);
        app(ScheduledReportService::class)->send($template, now());
        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail) => $mail->hasTo('managers@example.com') && $mail->attachmentPath !== null);

        foreach (['productivity_hourly', 'productivity_weekly', 'productivity_monthly'] as $code) {
            $html = app(ScheduledReportService::class)->render(EmailTemplate::query()->where('code', $code)->firstOrFail(), now())['html'];
            $this->assertStringContainsString('BY MODULE', $html, $code);
        }

        $this->assertSame('September 2026', app(ProductivityReport::class)->emailPeriod('productivity_month', now()->toImmutable(), 744)[2]);
        $this->assertSame('28 Sep – 04 Oct 2026', app(ProductivityReport::class)->emailPeriod('productivity_week', now()->toImmutable(), 168)[2]);
    }
}
