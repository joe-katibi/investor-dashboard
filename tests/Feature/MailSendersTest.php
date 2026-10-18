<?php

namespace Tests\Feature;

use App\Mail\OutageNotificationMail;
use App\Mail\ScheduledReportMail;
use App\Models\EmailTemplate;
use App\Models\NotificationLog;
use App\Services\Reports\ScheduledReportService;
use App\Services\Tickets\TicketTables;
use App\Support\MailSenders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** Each kind of e-mail comes from its own alias; a template may pick another one. */
class MailSendersTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        config([
            'mail.default' => 'array',
            'mail.from' => ['address' => 'noc@savannafibre.co.ug', 'name' => 'OMS'],
            'oms.mail_from' => [
                'outages' => 'outage@savannafibre.co.ug', 'customer_tickets' => 'customertickets@savannafibre.co.ug',
                'site_visits' => 'sitevisittickets@savannafibre.co.ug', 'mobility_outages' => '4Goutage@savannafibre.co.ug',
                'mobility_tickets' => '4gtickets@savannafibre.co.ug', 'executive' => 'summaryexecutive@savannafibre.co.ug',
                'alerts' => 'alerts-updates@savannafibre.co.ug',
            ],
        ]);
    }

    /** @return list<Email> */
    private function sent(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage())->all();
    }

    public function test_report_types_have_their_alias_and_a_template_may_choose_another(): void
    {
        $this->assertSame('outages', MailSenders::forReport('two_hour'));
        $this->assertSame('executive', MailSenders::forReport('executive'));
        $this->assertSame('mobility_outages', MailSenders::forReport('mobility_hourly'));
        $this->assertSame('mobility_tickets', MailSenders::forReport('ticket_4g_daily'));
        $this->assertSame('customer_tickets', MailSenders::forReport('ticket_daily'));
        $this->assertNull(MailSenders::forReport('custom'));

        $template = EmailTemplate::query()->create([
            'name' => 'Outage update', 'code' => 'outage_update_test', 'report_type' => 'custom', 'schedule_type' => 'interval', 'interval_minutes' => 120,
            'period_hours' => 2, 'to' => ['noc-team@savannafibre.co.ug'], 'subject' => 'Update', 'body' => 'Hello', 'is_active' => true,
        ]);

        app(ScheduledReportService::class)->send($template);
        $this->assertSame('noc@savannafibre.co.ug', $this->sent()[0]->getFrom()[0]->getAddress(), 'custom: the default address');

        $template->update(['report_type' => 'two_hour', 'body' => 'Hello']);
        app(ScheduledReportService::class)->send($template->refresh());
        $this->assertSame('outage@savannafibre.co.ug', $this->sent()[1]->getFrom()[0]->getAddress());

        $template->update(['sender' => MailSenders::SITE_VISITS]);
        app(ScheduledReportService::class)->send($template->refresh());
        $this->assertSame('sitevisittickets@savannafibre.co.ug', $this->sent()[2]->getFrom()[0]->getAddress());
        $this->assertSame('OMS', $this->sent()[2]->getFrom()[0]->getName());

        // An alias left empty in .env: the default address.
        config(['oms.mail_from.site_visits' => '']);
        app(ScheduledReportService::class)->send($template->refresh());
        $this->assertSame('noc@savannafibre.co.ug', $this->sent()[3]->getFrom()[0]->getAddress());
    }

    public function test_alerts_come_from_the_alerts_alias(): void
    {
        $log = NotificationLog::query()->create(['event' => 'outage_created', 'channel' => 'mail', 'recipient' => 'noc-team@savannafibre.co.ug',
            'subject' => 'Outage raised', 'body' => 'An outage', 'status' => 'pending', 'attempts' => 1]);

        Mail::to('noc-team@savannafibre.co.ug')->send(new OutageNotificationMail($log));

        $this->assertSame('alerts-updates@savannafibre.co.ug', $this->sent()[0]->getFrom()[0]->getAddress());
    }

    public function test_the_sla_chart_is_a_line_sent_as_an_inline_picture(): void
    {
        $chart = TicketTables::line(['Thu 15 Oct', 'Fri 16 Oct', 'Sat 17 Oct'], [
            'Closed within SLA %' => ['color' => '#0d9488', 'values' => [100.0, null, 84.3]],
        ], '%', 100.0);

        $this->assertStringContainsString('src="data:image/png;base64,', $chart);
        $this->assertStringContainsString('84.3%', $chart);
        $this->assertStringContainsString('Sat 17 Oct', $chart);

        Mail::to('noc-team@savannafibre.co.ug')->send(new ScheduledReportMail('Tickets', $chart));
        $email = $this->sent()[0];

        $this->assertStringNotContainsString('data:image/png', $email->getHtmlBody());
        $this->assertStringContainsString('src="cid:', $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('image/png', $email->getAttachments()[0]->getMediaType().'/'.$email->getAttachments()[0]->getMediaSubtype());
    }
}
