<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FifoQueue;
use App\Filament\Pages\FifoSettings;
use App\Filament\Pages\MyWork;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Outages\OutageResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Mail\ScheduledReportMail;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ScheduledReportService;
use App\Services\Reports\TemplateRenderer;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

class ReportsAndPanelTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    public function test_every_report_runs_and_exports_to_excel(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->makeUser('NOC Tier I', 'NOC');
        $this->reportOutage($agent, ['olt_ids' => [$this->makeOlt()->id]]);

        $registry = app(ReportRegistry::class);

        foreach (array_keys(ReportRegistry::REPORTS) as $key) {
            $report = $registry->run($key, ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]);
            $this->assertNotEmpty($report->columns, $key);
            $this->assertIsArray($report->rowsArray(), $key);
        }

        $path = app(ReportExporter::class)->writeXlsx($registry->run('outage_register'));
        $this->assertFileExists($path);
        $this->assertGreaterThan(1000, filesize($path));
        @unlink($path);
    }

    public function test_template_renderer_handles_loops_and_escapes_values(): void
    {
        $html = app(TemplateRenderer::class)->render(
            "Active: {{active_outages}}\n{{#for each active network outage}}{{ticket}} {{title}}\n{{/for}}{{summary_table}}",
            [
                'active_outages' => 2,
                'network_outages' => [['ticket' => 'OUT-1', 'title' => '<script>x</script>'], ['ticket' => 'OUT-2', 'title' => 'B']],
                'summary_table' => '<table><tr><td>ok</td></tr></table>',
            ],
        );

        $this->assertStringContainsString('Active: 2', $html);
        $this->assertStringContainsString('OUT-1 &lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringContainsString('OUT-2 B', $html);
        $this->assertStringContainsString('<table><tr><td>ok</td></tr></table>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_double_asterisks_make_text_bold_safely(): void
    {
        $renderer = app(TemplateRenderer::class);

        $html = $renderer->render(
            "**LIVE NETWORK OUTAGES**\n{{#for each active network outage}}\n**{{ticket}} | {{title}}**\nStatus: {{status}}\n{{/for}}**unclosed\nplain ** spaced **",
            ['network_outages' => [
                ['ticket' => 'OUT-1', 'title' => '<b>x</b> **not bold**', 'status' => "\x02sneaky\x03"],
            ]],
        );

        $this->assertStringContainsString('<strong>LIVE NETWORK OUTAGES</strong>', $html);
        $this->assertStringContainsString('<strong>OUT-1 | &lt;b&gt;x&lt;/b&gt; **not bold**</strong>', $html, 'values are escaped and never bolded');
        $this->assertStringContainsString('Status: sneaky', $html, 'marker characters in data are stripped');
        $this->assertStringContainsString('**unclosed', $html, 'an unmatched marker stays literal');
        $this->assertStringContainsString('plain ** spaced **', $html);
        $this->assertSame(substr_count($html, '<strong>'), substr_count($html, '</strong>'));

        $this->assertSame('Update OUT-1', $renderer->renderText('**Update** {{t}}', ['t' => 'OUT-1']), 'subjects drop bold markers');
    }

    public function test_reseeding_never_overwrites_edited_templates_but_upgrades_untouched_defaults(): void
    {
        $edited = EmailTemplate::query()->where('code', 'two_hour_update')->first();
        $edited->update(['body' => 'Our own wording', 'to' => ['ops@example.com'], 'is_active' => true]);

        // An installation still on the old default (same text, no bold markers).
        $untouched = EmailTemplate::query()->where('code', 'executive_summary')->first();
        $untouched->update(['body' => str_replace('**', '', EmailTemplateSeeder::EXECUTIVE_BODY)]);

        $this->seed(EmailTemplateSeeder::class);

        $edited->refresh();
        $this->assertSame('Our own wording', $edited->body);
        $this->assertSame(['ops@example.com'], $edited->to);
        $this->assertTrue($edited->is_active, 'activation survives a re-seed');
        $this->assertSame(EmailTemplateSeeder::EXECUTIVE_BODY, $untouched->refresh()->body, 'untouched default gets the bold headers');
    }

    public function test_scheduled_reports_are_claimed_once_and_sent(): void
    {
        Mail::fake();
        $template = EmailTemplate::query()->where('code', 'two_hour_update')->first();
        $template->update(['is_active' => true, 'to' => ['noc@example.com']]);

        $service = app(ScheduledReportService::class);
        $this->assertSame(1, $service->dispatchDue());
        $this->assertSame(0, $service->dispatchDue(), 'The same slot is never sent twice');

        Mail::assertSent(ScheduledReportMail::class);
        $this->assertDatabaseHas('notification_logs', ['email_template_id' => $template->id, 'status' => 'sent']);
    }

    public function test_panel_pages_render_for_super_admin(): void
    {
        $admin = $this->makeUser('Super Admin', 'MGMT');
        $agent = $this->makeUser('CX Agent', 'CX');
        $outage = $this->reportOutage($agent);

        $this->actingAs($admin);

        foreach ([
            Dashboard::getUrl(), MyWork::getUrl(), FifoQueue::getUrl(), Reports::getUrl(), FifoSettings::getUrl(),
            OutageResource::getUrl('index'), OutageResource::getUrl('create'),
            OutageResource::getUrl('view', ['record' => $outage]), OutageResource::getUrl('edit', ['record' => $outage]),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $this->get($resource::getUrl('index'))->assertOk();
        }
    }

    public function test_configuration_is_forbidden_without_permission(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->actingAs($agent);

        $this->get(FifoSettings::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(RoleResource::getUrl('index'))->assertForbidden();
        $this->get(OutageResource::getUrl('index'))->assertOk();
    }

    public function test_inactive_users_cannot_use_the_panel(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get(Dashboard::getUrl())->assertForbidden();
    }

    public function test_branding_title_and_icons(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('<title>Outage Management System | Login</title>', false)
            ->assertSee('favicon.ico', false)
            ->assertSee('apple-touch-icon.png', false)
            ->assertSee('images/oms-logo-128.png', false);

        foreach (['favicon.ico', 'favicon-32x32.png', 'apple-touch-icon.png', 'images/oms-logo.png', 'css/oms.css'] as $file) {
            $this->assertFileExists(public_path($file));
        }
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
