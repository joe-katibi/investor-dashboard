<?php

namespace Tests\Feature;

use App\Enums\StatusCode;
use App\Filament\Pages\FifoQueue;
use App\Filament\Pages\MobilityDashboard;
use App\Filament\Pages\MobilityEngineers;
use App\Filament\Pages\MobilitySettings;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Enodebs\EnodebResource;
use App\Filament\Resources\Enodebs\Pages\ManageEnodebs;
use App\Filament\Resources\ImpactRules\ImpactRuleResource;
use App\Filament\Resources\MobilityOutages\MobilityOutageResource;
use App\Filament\Resources\MobilityOutages\Pages\CreateMobilityOutage;
use App\Filament\Resources\MobilityOutages\Pages\ListMobilityOutages;
use App\Filament\Resources\MobilityOutages\Pages\ViewMobilityOutage;
use App\Filament\Resources\Outages\OutageResource;
use App\Filament\Resources\Outages\Pages\CreateOutage;
use App\Filament\Resources\Outages\Pages\ListOutages;
use App\Filament\Resources\TransmissionNodes\TransmissionNodeResource;
use App\Filament\Resources\Vendors\Pages\ManageVendors;
use App\Filament\Resources\Vendors\VendorResource;
use App\Filament\Resources\VendorTemplates\VendorTemplateResource;
use App\Filament\Resources\VendorUpdates\VendorUpdateResource;
use App\Filament\Support\OutageLink;
use App\Mail\ScheduledReportMail;
use App\Models\EmailTemplate;
use App\Models\Enodeb;
use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vertical;
use App\Services\Outages\OutageWorkflow;
use App\Services\Reports\MobilityReports;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ScheduledReportService;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** 4G NOC screens: pages, the report form, ticket actions, access separation, reports and the hourly e-mail. */
class MobilityScreensTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private User $noc;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        $this->workflow = app(OutageWorkflow::class);
        $this->noc = $this->makeUser('4G NOC', 'MOBILITY');
        $this->engineer = $this->makeUser('4G Engineer', 'MOBILITY');
    }

    private function type(string $code): int
    {
        return OutageType::query()->where('code', $code)->value('id');
    }

    private function site(string $id = '4624', string $name = 'Nsambya Kevina'): Enodeb
    {
        $vendor = Vendor::query()->where('code', 'ATC')->first();

        return Enodeb::query()->create(['site_id' => $id, 'name' => $name, 'tower_company' => 'ATC', 'vendor_id' => $vendor->id, 'is_active' => true]);
    }

    private function vendor(): Vendor
    {
        return tap(Vendor::query()->where('code', 'ATC')->firstOrFail())->update(['email' => 'ops@atc.example', 'contact_person' => 'ATC NOC']);
    }

    private function ticket(string $type, array $overrides = []): Outage
    {
        Auth::setUser($this->noc);

        return $this->workflow->createMobility(array_merge(['outage_type_id' => $this->type($type), 'title' => 'Site down'], $overrides), $this->noc);
    }

    public function test_every_4g_page_renders(): void
    {
        $internal = $this->ticket('mobility_enodeb', ['enodeb_ids' => [$this->site()->id], 'engineer_id' => $this->engineer->id]);
        $power = $this->ticket('mobility_power', ['vendor_id' => $this->vendor()->id]);
        $this->workflow->recordVendorUpdate($power, $this->noc, ['vendor_status' => 'investigating', 'method' => 'phone', 'comments' => 'Team on the way']);

        $this->actingAs($this->makeUser('4G NOC Manager', 'MOBILITY'));

        foreach ([
            MobilityDashboard::getUrl(), MobilityEngineers::getUrl(), MobilitySettings::getUrl(), MyWork::getUrl(), FifoQueue::getUrl(), Reports::getUrl(),
            MobilityOutageResource::getUrl('index'), MobilityOutageResource::getUrl('create'),
            MobilityOutageResource::getUrl('view', ['record' => $internal]), MobilityOutageResource::getUrl('edit', ['record' => $internal]),
            MobilityOutageResource::getUrl('view', ['record' => $power]),
            VendorResource::getUrl(), EnodebResource::getUrl(), TransmissionNodeResource::getUrl(), ImpactRuleResource::getUrl(),
            VendorTemplateResource::getUrl(), VendorUpdateResource::getUrl(),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(MobilityDashboard::getUrl())->assertSee($internal->ticket_number)->assertSee('Vendor performance');
        $this->get(MobilityOutageResource::getUrl('view', ['record' => $power]))->assertSee('Vendor Investigating')->assertSee('ATC');
    }

    public function test_noc_reports_a_4g_outage_from_the_form(): void
    {
        $site = $this->site();
        $vendor = $this->vendor();
        $this->actingAs($this->noc);

        // Power: the vendor is required and is e-mailed on save.
        Livewire::test(CreateMobilityOutage::class)
            ->fillForm(['outage_type_id' => $this->type('mobility_power'), 'title' => 'Nsambya Kevina — mains failure', 'description' => 'Batteries flat'])
            ->set('data.enodeb_ids', [$site->id])
            ->assertFormSet(['vendor_id' => $vendor->id]) // tower company of the site
            ->assertSee('4G Minor')
            ->set('data.vendor_id', null)
            ->call('create')
            ->assertHasFormErrors(['vendor_id'])
            ->set('data.vendor_id', $vendor->id)
            ->call('create')
            ->assertHasNoFormErrors();

        $power = Outage::query()->mobility()->latest('id')->firstOrFail();
        $this->assertSame('4G-PWR-000001', $power->ticket_number);
        $this->assertSame($vendor->id, $power->vendor_id);
        $this->assertSame([$site->id], $power->enodebs()->pluck('enodebs.id')->all());
        $this->assertTrue($power->status->isCode(StatusCode::MobilityVendorNotified));

        // eNodeB: assigned to an engineer straight from the form.
        Livewire::test(CreateMobilityOutage::class)
            ->fillForm(['outage_type_id' => $this->type('mobility_enodeb'), 'title' => 'Cell 2 down', 'cells_down' => 1, 'engineer_id' => $this->engineer->id])
            ->set('data.enodeb_ids', [$site->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $internal = Outage::query()->mobility()->latest('id')->firstOrFail();
        $this->assertSame('4G-ENB-000001', $internal->ticket_number);
        $this->assertSame($this->engineer->id, $internal->current_assignee_id);
        $this->assertTrue($internal->status->isCode(StatusCode::MobilityAssigned));

        // The FTTH "Report outage" form no longer offers the 4G vertical or the 4G types.
        $this->actingAs($this->makeUser('NOC Manager', 'NOC'));
        $mobility = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');
        Livewire::test(CreateOutage::class)
            ->fillForm(['title' => 'x', 'vertical_id' => $mobility, 'outage_type_id' => $this->type('mobility_power')])
            ->call('create')
            ->assertHasFormErrors(['vertical_id', 'outage_type_id']);
    }

    public function test_ticket_page_actions_follow_the_roles(): void
    {
        Storage::fake('local');
        $ticket = $this->ticket('mobility_enodeb', ['enodeb_ids' => [$this->site()->id]]);

        // NOC assigns; the engineer never sees "Verify & close".
        $this->actingAs($this->noc);
        Livewire::test(ViewMobilityOutage::class, ['record' => $ticket->id])
            ->assertActionVisible(TestAction::make('mobilityAssign'))
            ->assertActionHidden(TestAction::make('mobilityClose'))
            ->assertActionHidden(TestAction::make('mobilityVendorUpdate'))
            ->callAction(TestAction::make('mobilityAssign'), ['engineer_id' => $this->engineer->id, 'note' => 'Check the RRU'])
            ->assertHasNoActionErrors();
        $this->assertSame($this->engineer->id, $ticket->refresh()->current_assignee_id);

        $this->actingAs($this->engineer);
        Storage::disk('local')->put(OutageAttachment::DIRECTORY.'/2026/10/alarm.png', 'image');
        Livewire::test(ViewMobilityOutage::class, ['record' => $ticket->id])
            ->assertActionHidden(TestAction::make('mobilityAssign'))
            ->assertActionHidden(TestAction::make('mobilityClose'))
            ->assertActionHidden(TestAction::make('mobilityCancel'))
            ->assertActionHidden(TestAction::make('edit'))
            ->callAction(TestAction::make('mobilityStart'))
            ->callAction(TestAction::make('mobilityResolve'), ['root_cause' => 'Faulty RRU', 'resolution' => 'RRU replaced'])
            ->assertHasNoActionErrors()
            ->assertActionHidden(TestAction::make('mobilityResolve'))
            ->assertActionHidden(TestAction::make('mobilityClose'));
        $this->assertTrue($ticket->refresh()->status->isCode(StatusCode::MobilityResolvedPendingNoc));

        // My Work of the engineer / NOC opens the 4G ticket page, not the FTTH one.
        $this->assertStringContainsString('/4g/outages/'.$ticket->id, OutageLink::url($ticket));

        $this->actingAs($this->noc);
        Livewire::test(ViewMobilityOutage::class, ['record' => $ticket->id])
            ->callAction(TestAction::make('mobilityClose'), ['root_cause' => 'Faulty RRU', 'resolution' => 'RRU replaced', 'note' => 'All cells on air'])
            ->assertHasNoActionErrors()
            ->assertActionVisible(TestAction::make('mobilityReopen'))
            ->callAction(TestAction::make('mobilityReopen'), ['reason' => 'Down again'])
            ->assertHasNoActionErrors();
        $this->assertTrue($ticket->refresh()->status->isCode(StatusCode::MobilityOpen));

        // Attachments are private downloads for people who may see the ticket.
        $file = $this->workflow->addMobilityAttachments($ticket, $this->noc, [OutageAttachment::DIRECTORY.'/2026/10/alarm.png'], [OutageAttachment::DIRECTORY.'/2026/10/alarm.png' => 'alarm.png'])->first();
        $this->get($file->url())->assertOk()->assertHeader('content-disposition');
        $this->actingAs($this->makeUser('CX Agent', 'CX'))->get($file->url())->assertForbidden();

        // Vendor ticket: vendor update from the page.
        $power = $this->ticket('mobility_power', ['vendor_id' => $this->vendor()->id]);
        $this->actingAs($this->noc);
        Livewire::test(ViewMobilityOutage::class, ['record' => $power->id])
            ->assertActionHidden(TestAction::make('mobilityAssign'))
            ->callAction(TestAction::make('mobilityVendorUpdate'), ['vendor_status' => 'power_restored', 'method' => 'phone', 'comments' => 'Genset running'])
            ->assertHasNoActionErrors()
            ->assertActionVisible(TestAction::make('mobilityClose'));
        $this->assertTrue($power->refresh()->status->isCode(StatusCode::MobilityVendorResolvedPendingNoc));
    }

    public function test_4g_and_ftth_screens_are_separate(): void
    {
        $ticket = $this->ticket('mobility_enodeb', ['engineer_id' => $this->engineer->id]);
        $agent = $this->makeUser('CX Agent', 'CX');
        $ftth = $this->reportOutage($agent);

        // FTTH staff: no 4G menu, tickets or configuration.
        $this->actingAs($agent);
        foreach ([MobilityDashboard::getUrl(), MobilityOutageResource::getUrl('index'), MobilityOutageResource::getUrl('create'),
            MobilityOutageResource::getUrl('view', ['record' => $ticket]), VendorResource::getUrl(), MobilitySettings::getUrl()] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get(OutageResource::getUrl('view', ['record' => $ticket]))->assertNotFound();
        Livewire::test(ListOutages::class)->set('activeTab', 'all')->assertCanSeeTableRecords([$ftth])->assertCanNotSeeTableRecords([$ticket]);

        // 4G engineer: sees 4G tickets (and My Work), cannot create, configure or open FTTH.
        $this->actingAs($this->engineer);
        $this->get(MobilityOutageResource::getUrl('index'))->assertOk();
        // (ticket numbers are printed with non-breaking hyphens, so the title is what we look for)
        $this->get(MyWork::getUrl())->assertOk()->assertSee('Site down');
        $this->get(MobilityOutageResource::getUrl('create'))->assertForbidden();
        $this->get(MobilitySettings::getUrl())->assertForbidden();
        $this->get(OutageResource::getUrl('index'))->assertForbidden();
        $this->get(MobilityOutageResource::getUrl('view', ['record' => $ftth]))->assertNotFound();
        Livewire::test(ListMobilityOutages::class)->set('activeTab', 'all')->assertCanSeeTableRecords([$ticket])->assertCanNotSeeTableRecords([$ftth])
            ->set('activeTab', 'mine')->assertCanSeeTableRecords([$ticket])
            ->set('activeTab', 'power')->assertCanNotSeeTableRecords([$ticket]);
        Livewire::test(ManageVendors::class)->assertActionHidden(TestAction::make('create'));

        // FIFO queues: each side only sees its own queues.
        $this->actingAs($this->noc);
        $this->assertSame(['mobility_engineer', 'mobility_noc'], array_map(fn ($q) => $q->value, Livewire::test(FifoQueue::class)->instance()->visibleQueues()));
        $this->actingAs($this->makeUser('NOC Tier I', 'NOC'));
        $ftthQueues = array_map(fn ($q) => $q->value, Livewire::test(FifoQueue::class)->instance()->visibleQueues());
        $this->assertContains('noc_review', $ftthQueues);
        $this->assertNotContains('mobility_noc', $ftthQueues);

        // Reports are split the same way.
        $this->assertArrayHasKey('mobility_outages', ReportRegistry::availableTo($this->noc));
        $this->assertArrayNotHasKey('outage_register', ReportRegistry::availableTo($this->noc));
        $this->assertArrayNotHasKey('mobility_outages', ReportRegistry::availableTo($this->makeUser('NOC Manager', 'NOC')));
    }

    public function test_configuration_screens_work_for_the_4g_manager(): void
    {
        $manager = $this->makeUser('4G NOC Manager', 'MOBILITY');
        $this->actingAs($manager);

        Livewire::test(ManageVendors::class)
            ->callAction(TestAction::make('create'), ['name' => 'Eaton Towers', 'code' => 'eaton', 'vendor_type' => 'power', 'email' => 'noc@eaton.example'])
            ->assertHasNoActionErrors();
        $this->assertDatabaseHas('vendors', ['code' => 'EATON', 'email' => 'noc@eaton.example', 'created_by' => $manager->id]);

        Livewire::test(MobilitySettings::class)
            ->fillForm(['noc_contact' => '4G NOC 0700 000 000', 'vendor_silent_minutes' => 45, 'vendor_escalation_minutes' => 150])
            ->set('data.alert_emails', ['noc4g-alerts@example.com'])
            ->set('data.alert_phones', ['0700111222'])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(Settings::class);
        $settings->flush();
        $this->assertSame(45, $settings->int('mobility.vendor_silent_minutes'));
        $this->assertSame(['noc4g-alerts@example.com'], $settings->get('mobility.alert_emails'));
        $this->assertCount(1, $settings->get('mobility.alert_phones'));

        // Sites page carries the workbook import.
        Livewire::test(ManageEnodebs::class)->assertActionVisible(TestAction::make('importMobilityTopology'));
    }

    public function test_4g_reports_run_and_the_hourly_update_is_sent(): void
    {
        $site = $this->site();
        $closed = $this->ticket('mobility_enodeb', ['enodeb_ids' => [$site->id], 'engineer_id' => $this->engineer->id, 'start_time' => now()->subMinutes(50)]);
        Auth::setUser($this->engineer);
        $this->workflow->markMobilityResolved($closed, $this->engineer, ['root_cause' => 'Faulty RRU', 'resolution' => 'RRU replaced']);
        Auth::setUser($this->noc);
        $this->workflow->closeMobility($closed, $this->noc);
        $power = $this->ticket('mobility_power', ['enodeb_ids' => [$site->id], 'vendor_id' => $this->vendor()->id, 'title' => 'Mains failure <b>']);
        $this->workflow->recordVendorUpdate($power, $this->noc, ['vendor_status' => 'technician_dispatched', 'method' => 'phone', 'eta_at' => now()->addHour()]);
        $link = $this->ticket('mobility_transmission', ['enodeb_ids' => [$site->id], 'transmission_type' => 'microwave', 'transmission_link_id' => 'MW-17', 'title' => 'Microwave link down']);

        $registry = app(ReportRegistry::class);
        $filters = ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()];

        foreach (array_keys(MobilityReports::REPORTS) as $key) {
            $report = $registry->run($key, $filters);
            $this->assertNotEmpty($report->columns, $key);
            $this->assertNotEmpty($report->rowsArray(), $key);
        }

        $this->assertSame([$power->ticket_number], array_column($registry->run('mobility_power', $filters)->rowsArray(), 'ticket'));
        $this->assertSame(['MW-17', 'Microwave'], array_values(array_intersect_key($registry->run('mobility_transmission', $filters)->rowsArray()[0], array_flip(['link_id', 'link_type']))));
        $this->assertSame($link->ticket_number, $registry->run('mobility_transmission', $filters)->rowsArray()[0]['ticket']);
        $this->assertSame('Faulty RRU', $registry->run('mobility_root_cause', $filters)->rowsArray()[0]['root_cause']);
        $this->assertSame('ATC', $registry->run('mobility_vendor_performance', $filters)->rowsArray()[0]['vendor']);
        $this->assertSame($this->engineer->name, $registry->run('mobility_engineer_performance', $filters)->rowsArray()[0]['engineer']);
        $this->assertSame('100%', $registry->run('mobility_sla', $filters)->rowsArray()[0]['compliance']);
        // The FTTH register does not list 4G tickets.
        $this->assertNotContains($power->ticket_number, array_column($registry->run('outage_register', $filters)->rowsArray(), 'ticket'));

        $path = app(ReportExporter::class)->writeXlsx($registry->run('mobility_outages', $filters));
        $this->assertGreaterThan(1000, filesize($path));
        @unlink($path);

        // Hourly 4G update: seeded inactive, every hour once switched on.
        $template = EmailTemplate::query()->where('code', 'mobility_hourly_update')->firstOrFail();
        $this->assertFalse($template->is_active);
        $this->assertSame(60, $template->interval_minutes);
        $template->update(['is_active' => true, 'to' => ['4g-management@example.com']]);

        $service = app(ScheduledReportService::class);
        $rendered = $service->render($template->refresh());
        $this->assertStringContainsString('4G Outage Update', $rendered['subject']);
        $this->assertStringContainsString($power->ticket_number, $rendered['html']);
        $this->assertStringContainsString('Vendor ATC', $rendered['html']);
        $this->assertStringContainsString('Technician Dispatched', $rendered['html']);
        $this->assertStringContainsString($closed->ticket_number, $rendered['html'], 'closed this hour');
        $this->assertStringContainsString('Faulty RRU', $rendered['html']);
        $this->assertStringContainsString('Mains failure &lt;b&gt;', $rendered['html'], 'ticket text is escaped');
        $this->assertSame(2, $rendered['data']['g4_active']);
        $this->assertSame(1, $rendered['data']['g4_power']);
        $this->assertSame(1, $rendered['data']['g4_transmission']);
        $this->assertSame(Priority::query()->where('code', Priority::MOBILITY_MINOR)->value('name'), $rendered['data']['g4_outages'][0]['severity']);

        $this->assertSame(1, $service->dispatchDue());
        $this->assertSame(0, $service->dispatchDue());
        Mail::assertSent(ScheduledReportMail::class, fn (ScheduledReportMail $mail) => $mail->hasTo('4g-management@example.com'));
        $this->travel(61)->minutes();
        $this->assertSame(1, $service->dispatchDue(), 'due again an hour later');
    }
}
