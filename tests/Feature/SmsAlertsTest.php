<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Services\Notifications\Sms\AfricasTalkingSmsGateway;
use App\Services\Notifications\Sms\PhoneNumber;
use App\Services\Notifications\Sms\SmsGateway;
use App\Services\Notifications\Sms\TwilioSmsGateway;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** SMS alerts for the same lifecycle events as e-mail. */
class SmsAlertsTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    /** @var list<array{to: string, message: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();

        $sent = &$this->sent;
        $this->app->instance(SmsGateway::class, new class($sent) implements SmsGateway
        {
            public function __construct(private array &$sent) {}

            public function name(): string
            {
                return 'fake';
            }

            public function send(string $to, string $message): void
            {
                $this->sent[] = ['to' => $to, 'message' => $message];
            }
        });

        config(['oms.sms.default_country_code' => '256']);
        app(Settings::class)->set(['alerts.phones' => ['+256991000001', '0881 000 002']]);
    }

    private function smsTo(string $phone, string $event): bool
    {
        return NotificationLog::query()->where('channel', 'sms')->where('recipient', $phone)->where('event', $event)->where('status', 'sent')->exists();
    }

    public function test_lifecycle_events_are_sent_by_sms_to_the_alert_list_and_assignees(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $lead = $this->makeUser('NOC Team Leader', 'NOC', attributes: ['phone' => '0999000100', 'availability_status' => 'offline']);
        $noc = $this->makeUser('NOC Tier II', 'NOC', attributes: ['phone' => '+256999000101']);
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $crew = $this->makeTeam('Crew', 'INFRA', $team);
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team], ['phone' => '+256999000102']);
        $tech = $this->makeUser('Outage Technician', 'INFRA', [$crew], ['phone' => '+256999000103']);
        $workflow = app(OutageWorkflow::class);

        $outage = $this->reportOutage($agent);
        Auth::setUser($noc);
        $outage = $workflow->confirmByNoc($outage, $noc);
        Auth::setUser($dispatcher);
        $outage = $workflow->dispatch($outage, ['team_id' => $team->id, 'sub_team_id' => $crew->id], $dispatcher);
        Auth::setUser($tech);
        $workflow->addUpdate($outage, $tech, 'Crew on site');
        $outage = $workflow->reportRestored($this->withSitePhotos($outage, $tech), $tech, 'Spliced');
        Auth::setUser($noc);
        $outage = $workflow->confirmRestoration($outage, $noc);
        $workflow->resolve($outage, $noc, 'Fibre cut');

        // Alert list (local number normalised to +256)
        foreach (['outage_created', 'noc_escalation', 'infrastructure_escalation', 'dispatched', 'update_posted', 'restore_reported', 'resolved'] as $event) {
            $this->assertTrue($this->smsTo('+256991000001', $event), "list number 1 gets {$event}");
            $this->assertTrue($this->smsTo('+256881000002', $event), "list number 2 gets {$event}");
        }

        // Assignees at each stage
        $this->assertTrue($this->smsTo('+256999000101', 'assigned'), 'NOC assignee');
        $this->assertTrue($this->smsTo('+256999000102', 'assigned'), 'Infrastructure dispatcher');
        $this->assertTrue($this->smsTo('+256999000103', 'assigned'), 'technician');

        // Role-based rules (NOC Team Leader) by SMS too
        $this->assertTrue($this->smsTo('+256999000100', 'outage_created'));
        $this->assertTrue($this->smsTo('+256999000100', 'restore_reported'));

        // Messages are short and identify the ticket
        $this->assertNotEmpty($this->sent);
        foreach ($this->sent as $sms) {
            $this->assertLessThanOrEqual(306, mb_strlen($sms['message']));
            $this->assertStringStartsWith('OMS: ', $sms['message']);
        }
    }

    public function test_sms_events_and_assignee_sms_can_be_switched_off_independently(): void
    {
        app(Settings::class)->set(['alerts.sms_events' => ['resolved'], 'alerts.sms_assignee' => false, 'alerts.emails' => ['ops@example.com']]);
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->makeUser('NOC Tier I', 'NOC', attributes: ['phone' => '+256999000200']);

        $this->reportOutage($agent);

        $this->assertFalse($this->smsTo('+256991000001', 'outage_created'));
        $this->assertFalse($this->smsTo('+256999000200', 'assigned'));
        $this->assertTrue(NotificationLog::query()->where('channel', 'mail')->where('event', 'outage_created')->exists(), 'e-mail alerts unaffected');
    }

    public function test_phone_numbers_are_normalised_and_invalid_ones_rejected(): void
    {
        config(['oms.sms.default_country_code' => '256']);

        $this->assertSame('+256991234567', PhoneNumber::normalise('0991 234 567'));
        $this->assertSame('+256991234567', PhoneNumber::normalise('+256 99-123-4567'));
        $this->assertSame('+256991234567', PhoneNumber::normalise('00256991234567'));
        $this->assertNull(PhoneNumber::normalise('12'));
        $this->assertNull(PhoneNumber::normalise('abc'));
        $this->assertNull(PhoneNumber::normalise(''));
    }

    public function test_africas_talking_gateway_sends_and_detects_rejections(): void
    {
        Http::fake([
            'api.africastalking.com/*' => Http::sequence()
                ->push(['SMSMessageData' => ['Recipients' => [['statusCode' => 101, 'status' => 'Success']]]])
                ->push(['SMSMessageData' => ['Recipients' => [['statusCode' => 405, 'status' => 'InsufficientBalance']]]]),
        ]);
        $gateway = new AfricasTalkingSmsGateway('oms', 'secret-key', 'OMSALERT', sandbox: false);

        $gateway->send('+256991234567', 'hello');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.africastalking.com/version1/messaging'
            && $request->hasHeader('apiKey', 'secret-key')
            && $request['to'] === '+256991234567'
            && $request['username'] === 'oms'
            && $request['from'] === 'OMSALERT');

        $this->expectException(RuntimeException::class);
        $gateway->send('+256991234567', 'hello again');
    }

    public function test_twilio_gateway_posts_form_with_basic_auth(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        (new TwilioSmsGateway('AC123', 'tok', '+15005550006'))->send('+256991234567', 'hello');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/Accounts/AC123/Messages.json')
            && $request['To'] === '+256991234567' && $request['From'] === '+15005550006' && $request['Body'] === 'hello');
    }
}
