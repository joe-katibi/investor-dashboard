<?php

namespace Tests\Feature;

use App\Enums\NotificationEvent;
use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Events\MobileNotification;
use App\Models\MobileDevice;
use App\Models\Outage;
use App\Models\OutagePhoto;
use App\Models\OutageType;
use App\Models\Team;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Outages\OutageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * The mobile app's API (/api/v1): sign-in, who may use it, which tickets a person may see (and that
 * knowing a ticket id is never enough), the server-driven actions for dispatchers, technicians and
 * 4G engineers, photos, notifications and devices.
 */
class MobileApiTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private Team $team;

    private User $dispatcher;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday, mid-morning: inside the 07:00–18:00 SLA day of customer work.
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);

        $this->team = $this->makeTeam('Field A', 'INFRA');
        $this->dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$this->team], ['email' => 'dispatch@example.com']);
        $this->technician = $this->makeUser('Outage Technician', 'INFRA', [$this->team], ['email' => 'tech@example.com', 'username' => 'tech.one']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Sign in through the API, as the app does, and return the bearer token. */
    private function token(User $user): string
    {
        // Each request below authenticates from its token alone, as on a real phone.
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'password', 'device_name' => 'Test phone'])
            ->assertOk()->json('token');
    }

    private function api(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        $token = $this->tokens[$user->id] ??= $this->token($user);
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->json($method, '/api/v1/'.ltrim($uri, '/'), $data);
    }

    /** @var array<int, string> */
    private array $tokens = [];

    /** Run an action with files (multipart, as the app uploads photos). */
    private function act(User $user, Outage $ticket, string $action, array $data = []): TestResponse
    {
        $token = $this->tokens[$user->id] ??= $this->token($user);
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->withHeader('Accept', 'application/json')
            ->post("/api/v1/tickets/{$ticket->id}/actions/{$action}", $data);
    }

    /**
     * With OMS_MOBILE_FIXTURES=<folder> set, the real answers of the API are written there as JSON. The
     * mobile app's own tests read them (test/fixtures/api), so the app is checked against what this
     * server really sends — not against a hand-written copy of it.
     */
    private function fixture(string $name, TestResponse $response): TestResponse
    {
        if ($directory = env('OMS_MOBILE_FIXTURES')) {
            $json = $response->json();

            // Nothing from this machine's own configuration goes into a fixture.
            if (isset($json['token'])) {
                $json['token'] = 'test-token';
            }

            if (isset($json['realtime']['key'])) {
                $json['realtime']['key'] = 'test-key';
            }

            File::ensureDirectoryExists($directory);
            File::put($directory.'/'.$name.'.json', json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        return $response;
    }

    /** @return list<string> */
    private function actions(User $user, Outage $ticket): array
    {
        return array_column($this->api($user, 'GET', "tickets/{$ticket->id}")->assertOk()->json('ticket.actions'), 'key');
    }

    /** An FTTH outage, confirmed by the NOC and so waiting for the dispatcher. */
    private function confirmedOutage(): Outage
    {
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $outage = $this->reportOutage($this->makeUser('CX Agent', 'CX'), ['title' => 'Kira FDT 4 down', 'location' => 'Kira']);
        Auth::setUser($noc);

        return $this->workflow->confirmByNoc($outage, $noc);
    }

    // ------------------------------------------------------------------ sign-in

    public function test_sign_in_is_for_active_field_staff_and_a_token_can_be_revoked(): void
    {
        // Wrong password, unknown account and inactive account all get the same answer.
        $this->postJson('/api/v1/auth/login', ['login' => 'tech@example.com', 'password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('login');
        $this->postJson('/api/v1/auth/login', ['login' => 'nobody@example.com', 'password' => 'password'])->assertStatus(422)->assertJsonValidationErrors('login');
        $inactive = $this->makeUser('Outage Technician', 'INFRA', attributes: ['is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['login' => $inactive->email, 'password' => 'password'])->assertStatus(422);

        // Not a dispatcher, technician or engineer: no mobile app.
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->postJson('/api/v1/auth/login', ['login' => $agent->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'not_mobile_user')
            ->assertJsonPath('message', 'Your account is not authorized to use the mobile application.');

        // E-mail or username; the answer never contains the password or the stored secret.
        $login = $this->fixture('login', $this->postJson('/api/v1/auth/login', ['login' => 'tech.one', 'password' => 'password', 'device_name' => 'Pixel 8'])->assertOk());
        $login->assertJsonPath('user.mobile_role', 'technician')->assertJsonPath('user.mobile_role_label', 'Technician')
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.app_authentication_secret');
        $token = $login->json('token');
        $this->assertNotNull($login->json('expires_at'));

        // No token, a made-up token: 401 in JSON, never a redirect to the login page.
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer 999|nonsense')->getJson('/api/v1/me')->assertUnauthorized();

        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('user.name', $this->technician->name)->assertJsonPath('user.teams', ['Field A']);

        // The roles of the app.
        $this->assertSame('dispatch', $this->api($this->dispatcher, 'GET', 'me')->json('user.mobile_role'));
        $this->assertSame('engineer', $this->api($this->makeUser('4G Engineer', 'MOBILITY'), 'GET', 'me')->json('user.mobile_role'));
        $this->assertSame('technician', $this->api($this->makeUser('4G Technician', 'MOBILITY'), 'GET', 'me')->json('user.mobile_role'));
        $this->assertSame('technician', $this->api($this->makeUser('Site Visit Technician', 'DISPATCH'), 'GET', 'me')->json('user.mobile_role'));

        // A token that was not issued by the app's sign-in is refused.
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->technician->createToken('other', ['something-else'])->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();

        // An expired sign-in asks for the password again.
        Auth::forgetGuards();
        $this->travel((int) config('oms.mobile.token_days') + 1)->days();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->travelBack();
        Carbon::setTestNow('2026-10-05 10:00:00');

        // Deactivated while signed in: the next request is refused and the token is gone.
        $fresh = $this->token($this->technician);
        $this->technician->update(['is_active' => false]);
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$fresh)->getJson('/api/v1/me')->assertUnauthorized();
        $this->technician->update(['is_active' => true]);
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$fresh)->getJson('/api/v1/me')->assertUnauthorized();

        // Sign-out ends that phone's session only.
        $first = $this->token($this->dispatcher);
        $second = $this->token($this->dispatcher);
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$first)->postJson('/api/v1/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$first)->getJson('/api/v1/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$second)->getJson('/api/v1/me')->assertOk();
    }

    public function test_two_step_sign_in_applies_to_the_app_too(): void
    {
        $google = app(Google2FA::class);
        $secret = $google->generateSecretKey(16);
        $this->technician->saveAppAuthenticationSecret($secret);
        $this->technician->save();

        $this->postJson('/api/v1/auth/login', ['login' => 'tech@example.com', 'password' => 'password'])
            ->assertStatus(422)->assertJsonPath('code', 'mfa_required');
        $this->postJson('/api/v1/auth/login', ['login' => 'tech@example.com', 'password' => 'password', 'code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/login', ['login' => 'tech@example.com', 'password' => 'password', 'code' => $google->getCurrentOtp($secret)])
            ->assertOk()->assertJsonStructure(['token', 'user']);

        // Required for everyone: an account that has not set it up cannot use the app yet.
        config(['oms.security.require_mfa' => true]);
        $this->postJson('/api/v1/auth/login', ['login' => 'dispatch@example.com', 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'mfa_setup_required');
    }

    // ------------------------------------------------------------------ FTTH outage: dispatcher → technician

    public function test_dispatcher_dispatches_and_the_technician_works_the_outage_from_the_app(): void
    {
        $other = $this->makeUser('Outage Technician', 'INFRA', [$this->makeTeam('Field B', 'INFRA')], ['availability_status' => 'offline']);
        $outage = $this->confirmedOutage();
        $this->assertSame($this->dispatcher->id, $outage->current_assignee_id, 'FIFO gave it to the dispatcher');

        // The dispatcher's dashboard and list.
        $dashboard = $this->fixture('dashboard_dispatcher', $this->api($this->dispatcher, 'GET', 'dashboard')->assertOk());
        $this->assertSame(1, collect($dashboard->json('tiles'))->firstWhere('scope', 'awaiting')['count']);
        $list = $this->fixture('tickets_awaiting', $this->api($this->dispatcher, 'GET', 'tickets?scope=awaiting')->assertOk());
        $list->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.number', $outage->ticket_number)
            ->assertJsonPath('data.0.module', 'outage')->assertJsonPath('data.0.my_assignment.needs_acceptance', true);

        // A technician knows nothing of a ticket that is not theirs — whatever id they ask for.
        $this->api($this->technician, 'GET', 'tickets')->assertOk()->assertJsonPath('meta.total', 0);
        $this->api($this->technician, 'GET', "tickets/{$outage->id}")->assertForbidden()
            ->assertJsonPath('message', 'You don\'t have permission to view this ticket.');
        $this->api($this->technician, 'GET', 'tickets/999999')->assertNotFound();
        $this->act($this->technician, $outage, 'add_update', ['body' => 'I should not be here'])->assertForbidden();

        // The server says what the dispatcher can do, and with which form.
        $detail = $this->fixture('ticket_outage_dispatcher', $this->api($this->dispatcher, 'GET', "tickets/{$outage->id}")->assertOk());
        $this->assertSame(['accept', 'reject', 'dispatch', 'add_update'], array_column($detail->json('ticket.actions'), 'key'));
        $dispatch = collect($detail->json('ticket.actions'))->firstWhere('key', 'dispatch');
        $this->assertSame(['team_id', 'technician_id', 'note'], array_column($dispatch['fields'], 'name'));
        $technicians = collect($dispatch['fields'])->firstWhere('name', 'technician_id');
        $this->assertSame('team_id', $technicians['depends_on']);
        $this->assertContains(['value' => (string) $this->technician->id, 'group' => (string) $this->team->id], array_map(fn ($o) => ['value' => $o['value'], 'group' => $o['group']], $technicians['options']));

        // Input is checked on the server: the team is mandatory and must be one of the options.
        $this->act($this->dispatcher, $outage, 'dispatch', ['note' => 'Go'])->assertStatus(422)->assertJsonValidationErrors('team_id');
        $this->act($this->dispatcher, $outage, 'dispatch', ['team_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('team_id');
        $this->act($this->dispatcher, $outage, 'no_such_action')->assertStatus(422)->assertJsonValidationErrors('action');

        $this->act($this->dispatcher, $outage, 'dispatch', ['team_id' => $this->team->id, 'technician_id' => $this->technician->id, 'note' => 'Fibre cut near the junction'])
            ->assertOk()->assertJsonPath('message', 'Dispatched')->assertJsonPath('ticket.status.code', StatusCode::TeamDispatched->value)
            ->assertJsonPath('ticket.assignment.technician', $this->technician->name);

        // The technician: new assignment → accept → update → restored.
        $this->assertSame(1, collect($this->api($this->technician, 'GET', 'dashboard')->json('tiles'))->firstWhere('scope', 'new')['count']);
        $this->api($this->technician, 'GET', 'tickets?scope=new')->assertJsonPath('data.0.id', $outage->id);
        $this->fixture('dashboard_technician', $this->api($this->technician, 'GET', 'dashboard'));
        $this->fixture('ticket_outage_technician', $this->api($this->technician, 'GET', "tickets/{$outage->id}"));
        $this->assertSame(['accept', 'reject', 'site_photos', 'report_restored', 'request_support', 'set_status', 'add_update'], $this->actions($this->technician, $outage));
        $this->api($other, 'GET', "tickets/{$outage->id}")->assertForbidden();

        $this->act($this->technician, $outage, 'accept')->assertOk()->assertJsonPath('ticket.my_assignment.status', 'accepted');
        $this->assertNotContains('accept', $this->actions($this->technician, $outage));
        $this->act($this->technician, $outage, 'add_update', ['body' => 'On site, splicing'])->assertOk();
        $this->act($this->technician, $outage, 'report_restored', ['note' => ''])->assertStatus(422)->assertJsonValidationErrors('note');
        // A restoration time in the future is refused.
        $this->act($this->technician, $outage, 'report_restored', ['note' => 'Spliced', 'restored_at' => now()->addHour()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('restored_at');

        // A Regular outage is reported restored with three site photos — camera only, in the app.
        $detail = $this->api($this->technician, 'GET', "tickets/{$outage->id}")->assertOk();
        $sitePhotos = collect($detail->json('ticket.actions'))->firstWhere('key', 'site_photos');
        $this->assertSame('Site photos (0 of 3)', $sitePhotos['label']);
        $this->assertSame(['site_findings', 'site_restored', 'site_power'], array_column($sitePhotos['fields'], 'name'));
        $this->assertSame(['Findings on ground', 'After restoration', 'Power readings'], array_column($sitePhotos['fields'], 'label'));
        $this->assertSame(['camera', 'camera', 'camera'], array_column($sitePhotos['fields'], 'capture'), 'nothing from the gallery');
        $this->assertSame([false, false, false], array_column($sitePhotos['fields'], 'required'), 'one at a time, as the work goes');
        $restore = collect($detail->json('ticket.actions'))->firstWhere('key', 'report_restored');
        $this->assertSame(['restored_at', 'note', 'site_findings', 'site_restored', 'site_power'], array_column($restore['fields'], 'name'));

        // Without them the restoration is refused; so is a "photo" that is not a picture.
        $this->act($this->technician, $outage, 'report_restored', ['note' => 'Spliced'])
            ->assertStatus(422)->assertJsonValidationErrors(['site_findings', 'site_restored', 'site_power']);
        $this->act($this->technician, $outage, 'site_photos')->assertStatus(422)->assertJsonValidationErrors('photos');
        $this->act($this->technician, $outage, 'site_photos', ['site_findings' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php')])
            ->assertStatus(422)->assertJsonValidationErrors('site_findings');
        $this->assertSame(0, OutagePhoto::query()->count());

        // The findings are photographed on arrival, before the repair: the phone says when.
        $takenAt = now()->subMinutes(40);
        $this->act($this->technician, $outage, 'site_photos', [
            'site_findings' => UploadedFile::fake()->image('findings.jpg', 1200, 900), 'site_findings_taken_at' => $takenAt->toIso8601String(),
        ])->assertOk()->assertJsonPath('message', 'Saved — still to take: After restoration, Power readings');

        $findings = OutagePhoto::query()->sole();
        $this->assertSame([OutagePhoto::SITE, 'findings', OutagePhoto::SOURCE_CAMERA, $this->technician->id], [$findings->stage, $findings->position, $findings->source, $findings->user_id]);
        $this->assertSame($takenAt->toDateTimeString(), $findings->taken_at->toDateTimeString(), 'when the camera took it, in server time');
        Storage::disk('local')->assertExists($findings->path);

        $detail = $this->fixture('ticket_outage_site_photos', $this->api($this->technician, 'GET', "tickets/{$outage->id}")->assertOk());
        $this->assertSame('Site photos (1 of 3)', collect($detail->json('ticket.actions'))->firstWhere('key', 'site_photos')['label']);
        $this->assertSame(
            ['restored_at', 'note', 'site_restored', 'site_power'],
            array_column(collect($detail->json('ticket.actions'))->firstWhere('key', 'report_restored')['fields'], 'name'),
            'only the photos still missing are asked for',
        );
        $shown = collect($detail->json('ticket.attachments'))->firstWhere('label', 'Findings on ground');
        $this->assertSame($takenAt->toIso8601String(), $shown['at']);
        $this->api($this->technician, 'GET', str_replace(url('/api/v1'), '', $shown['url']))->assertOk();
        $this->assertContains('Site photo added — Findings on ground. Still to take: After restoration, Power readings.', array_column($detail->json('ticket.timeline'), 'body'));

        // Still one short: refused, and nothing is half-saved.
        $this->act($this->technician, $outage, 'report_restored', ['note' => 'Spliced', 'site_restored' => UploadedFile::fake()->image('after.jpg')])
            ->assertStatus(422)->assertJsonValidationErrors('site_power');
        $this->assertSame(1, OutagePhoto::query()->count());

        $restored = $this->fixture('action_report_restored', $this->act($this->technician, $outage, 'report_restored', [
            'note' => 'Core respliced, light levels normal', 'restored_at' => now()->subMinutes(3)->toIso8601String(),
            'site_restored' => UploadedFile::fake()->image('after.jpg', 1200, 900), 'site_restored_taken_at' => now()->subMinutes(4)->toIso8601String(),
            // A phone whose clock runs ahead cannot date a photo in the future.
            'site_power' => UploadedFile::fake()->image('power.jpg', 1200, 900), 'site_power_taken_at' => now()->addDay()->toIso8601String(),
        ]))->assertOk()->assertJsonPath('ticket.status.code', StatusCode::RestoreReported->value);
        $this->assertSame([], $outage->refresh()->missingSitePhotos());
        $this->assertSame(now()->toDateTimeString(), OutagePhoto::query()->where('position', 'power')->sole()->taken_at->toDateTimeString());
        $this->assertSame(
            ['Findings on ground', 'After restoration', 'Power readings'],
            array_slice(array_column($restored->json('ticket.attachments'), 'label'), 0, 3),
        );
        // Nothing left to do for the technician; the ticket is "pending confirmation" and still readable.
        $this->assertSame([], $restored->json('ticket.actions'));
        $this->assertSame(now()->subMinutes(3)->toDateTimeString(), $outage->refresh()->restored_at->toDateTimeString(), 'the phone\'s time is stored in server time');
        $this->api($this->technician, 'GET', 'tickets?scope=pending')->assertJsonPath('meta.total', 1);
        $this->api($this->technician, 'GET', 'tickets?scope=mine')->assertJsonPath('meta.total', 0);

        $timeline = array_column($this->api($this->technician, 'GET', "tickets/{$outage->id}")->json('ticket.timeline'), 'body');
        $this->assertContains('On site, splicing', $timeline);
        $this->assertContains('Core respliced, light levels normal', $timeline);

        // Search and filters.
        $this->api($this->dispatcher, 'GET', 'tickets?scope=open&search=kira')->assertJsonPath('meta.total', 1);
        $this->api($this->dispatcher, 'GET', 'tickets?scope=open&search=nothing-like-this')->assertJsonPath('meta.total', 0);
        $this->api($this->dispatcher, 'GET', 'tickets?scope=open&module=mobility')->assertJsonPath('meta.total', 0);
        $this->api($this->dispatcher, 'GET', 'tickets?scope=pending')->assertJsonPath('meta.total', 1);
    }

    // ------------------------------------------------------------------ site visit with photos

    public function test_site_visit_technician_completes_a_visit_with_photos_and_the_dispatcher_closes_it(): void
    {
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH', attributes: ['phone' => '0700555666']);
        $stranger = $this->makeUser('Site Visit Technician', 'DISPATCH', attributes: ['availability_status' => 'offline']);
        $tier2 = $this->makeUser('CX Tier II', 'CX');
        Auth::setUser($tier2);

        $visit = $this->workflow->createSiteVisit([
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'),
            'subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => 'SF2659301', 'customer_name' => 'John Customer', 'address' => 'Lukuli water pump',
            // Called from the number on the account; a second number to try if that one is off.
            'calling_number' => '0772000010', 'primary_number' => '0772000010', 'alt_number_1' => '0701000020',
        ], $tier2);

        // Dispatcher: assign from the app.
        $this->assertContains('visit_assign', $this->actions($dispatcher, $visit));
        $this->act($dispatcher, $visit, 'visit_assign', ['technician_id' => $technician->id, 'note' => 'Go first thing'])
            ->assertOk()->assertJsonPath('ticket.assignment.technician', $technician->name);

        // Technician: the customer's details, then the visit step by step.
        $detail = $this->api($technician, 'GET', "tickets/{$visit->id}")->assertOk();
        $detail->assertJsonPath('ticket.module', 'site_visit')->assertJsonPath('ticket.customer.name', 'John Customer')
            ->assertJsonPath('ticket.customer.phones', ['0772000010', '0701000020'])->assertJsonPath('ticket.customer.address', 'Lukuli water pump')
            // Each number named, none dropped for repeating another: the technician chooses which to call.
            ->assertJsonPath('ticket.customer.contacts', [
                ['key' => 'calling', 'label' => 'Calling number', 'number' => '0772000010'],
                ['key' => 'primary', 'label' => 'Primary number', 'number' => '0772000010'],
                ['key' => 'alt_1', 'label' => 'Alternative number 1', 'number' => '0701000020'],
            ]);
        $this->assertContains('visit_en_route', array_column($detail->json('ticket.actions'), 'key'));
        $this->api($stranger, 'GET', "tickets/{$visit->id}")->assertForbidden();

        $this->act($technician, $visit, 'visit_en_route')->assertOk()->assertJsonPath('message', 'On the way');
        $this->act($technician, $visit, 'visit_arrive')->assertOk();
        $this->assertNotContains('visit_en_route', $this->actions($technician, $visit));

        $complete = collect($this->fixture('ticket_visit_technician', $this->api($technician, 'GET', "tickets/{$visit->id}"))->json('ticket.actions'))->firstWhere('key', 'visit_complete');
        $fields = collect($complete['fields'])->keyBy('name');
        $this->assertTrue($fields['fat_number']['required']);
        $this->assertSame('photo', $fields['geo_photo']['type']);
        $this->assertTrue($fields['geo_photo']['required']);
        $reason = $fields['final_reason_id']['options'][0]['value'];

        // Evidence is mandatory, and a "photo" must really be an image.
        $this->act($technician, $visit, 'visit_complete', ['final_reason_id' => $reason, 'resolution_notes' => 'Respliced', 'fat_number' => 'FAT-112'])
            ->assertStatus(422)->assertJsonValidationErrors(['geo_photo', 'speed_photo']);
        $this->act($technician, $visit, 'visit_complete', [
            'final_reason_id' => $reason, 'resolution_notes' => 'Respliced', 'fat_number' => 'FAT-112',
            'geo_photo' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php'), 'speed_photo' => UploadedFile::fake()->image('speed.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('geo_photo');

        $done = $this->act($technician, $visit, 'visit_complete', [
            'final_reason_id' => $reason, 'resolution_notes' => 'Broken core respliced', 'fat_number' => 'FAT-112', 'drop_cable_m' => 40,
            'geo_photo' => UploadedFile::fake()->image('geo.jpg', 800, 600), 'speed_photo' => UploadedFile::fake()->image('speed.jpg', 800, 600),
        ])->assertOk()->assertJsonPath('message', 'Completed — sent to the dispatcher');
        $this->fixture('action_visit_complete', $done);
        $this->fixture('ticket_visit_dispatcher', $this->api($dispatcher, 'GET', "tickets/{$visit->id}"));
        $done->assertJsonPath('ticket.status.code', StatusCode::VisitPendingVerification->value)->assertJsonPath('ticket.visit.fat_number', 'FAT-112');
        $this->assertSame(QueueType::VisitDispatch, $visit->refresh()->current_queue);

        // The photos are private: opened with the token of someone who may see the visit, nobody else.
        $files = $done->json('ticket.attachments');
        $this->assertSame(['Geolocation photo', 'Speed test photo'], array_column($files, 'label'));
        $path = parse_url($files[0]['url'], PHP_URL_PATH);
        $this->assertStringStartsWith('/api/v1/attachments/', $path);
        $this->api($technician, 'GET', substr($path, strlen('/api/v1/')))->assertOk();
        $this->api($stranger, 'GET', substr($path, strlen('/api/v1/')))->assertForbidden();
        Auth::forgetGuards();
        $this->flushHeaders()->getJson($path)->assertUnauthorized();

        // Dispatcher: final close from the app.
        $this->api($dispatcher, 'GET', 'tickets?scope=pending')->assertJsonPath('data.0.id', $visit->id);
        $this->assertContains('visit_verify', $this->actions($dispatcher, $visit));
        $this->act($dispatcher, $visit, 'visit_verify', ['note' => 'Link online'])->assertOk()->assertJsonPath('message', 'Verified');
        $this->assertNotNull($visit->refresh()->ticket->verified_at);
    }

    // ------------------------------------------------------------------ 4G: engineers work transmission tickets

    public function test_4g_engineer_works_a_transmission_ticket_from_the_app(): void
    {
        $noc = $this->makeUser('4G NOC', 'MOBILITY');
        $engineer = $this->makeUser('4G Engineer', 'MOBILITY');
        $technician = $this->makeUser('4G Technician', 'MOBILITY');
        Auth::setUser($noc);

        $ticket = $this->workflow->createMobility([
            'outage_type_id' => OutageType::query()->where('code', 'mobility_transmission')->value('id'),
            'title' => 'Microwave link down', 'transmission_type' => 'microwave', 'transmission_link_id' => 'MW-17',
        ], $noc);
        $this->assertSame($engineer->id, $ticket->current_assignee_id);

        // The 4G NOC works on the web; the app is for the field.
        $this->postJson('/api/v1/auth/login', ['login' => $noc->email, 'password' => 'password'])->assertForbidden();
        $this->api($technician, 'GET', "tickets/{$ticket->id}")->assertForbidden();

        $detail = $this->fixture('ticket_mobility_engineer', $this->api($engineer, 'GET', "tickets/{$ticket->id}")->assertOk());
        $detail->assertJsonPath('ticket.module', 'mobility')->assertJsonPath('ticket.network.domain', 'Transmission')->assertJsonPath('ticket.network.link', 'MW-17 · Microwave');
        $this->assertSame(['accept', 'reject', 'mobility_start', 'mobility_resolve', 'mobility_status', 'mobility_attach', 'add_update'], array_column($detail->json('ticket.actions'), 'key'));

        $this->act($engineer, $ticket, 'mobility_start', ['note' => 'Heading to the hub'])->assertOk()->assertJsonPath('ticket.status.code', StatusCode::MobilityInProgress->value);
        $this->act($engineer, $ticket, 'mobility_attach', ['files' => [UploadedFile::fake()->image('odu.jpg')], 'note' => 'ODU alarm'])->assertOk();
        $this->act($engineer, $ticket, 'mobility_resolve', ['root_cause' => 'Faulty ODU', 'resolution' => 'ODU replaced'])
            ->assertOk()->assertJsonPath('ticket.status.code', StatusCode::MobilityResolvedPendingNoc->value)->assertJsonPath('ticket.actions', []);
        $this->assertSame('Faulty ODU', $ticket->refresh()->root_cause);
        $this->assertSame(1, $ticket->attachments()->count());
    }

    // ------------------------------------------------------------------ notifications, devices, availability

    public function test_notifications_devices_and_availability(): void
    {
        Event::fake([MobileNotification::class]);

        // The phone registers itself after sign-in; a person can have several.
        $device = $this->api($this->technician, 'POST', 'devices', ['device_uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'platform' => 'android', 'app_version' => '1.0.0'])
            ->assertCreated()->json('device.id');
        $this->api($this->technician, 'POST', 'devices', ['device_uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890', 'platform' => 'android', 'app_version' => '1.0.1'])->assertOk();
        $this->api($this->technician, 'POST', 'devices', ['device_uuid' => 'short', 'platform' => 'windows'])->assertStatus(422)->assertJsonValidationErrors(['device_uuid', 'platform']);
        $this->assertSame(1, MobileDevice::query()->where('user_id', $this->technician->id)->count());
        $this->api($this->dispatcher, 'DELETE', "devices/{$device}")->assertNotFound();

        // A ticket is dispatched to the technician: a notification in their list, and — for an app that
        // is open — a small broadcast over Reverb on their own private channel.
        $outage = $this->confirmedOutage();
        Auth::setUser($this->dispatcher);
        $this->workflow->dispatch($outage, ['team_id' => $this->team->id, 'technician_id' => $this->technician->id], $this->dispatcher);

        Event::assertDispatched(MobileNotification::class, function (MobileNotification $event) use ($outage): bool {
            return $event->userId === $this->technician->id && $event->ticketId === $outage->id
                && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$this->technician->id
                && $event->broadcastAs() === 'mobile.notification'
                // Only identifiers and a title travel: the app fetches the ticket, and the server decides.
                && array_keys($event->broadcastWith()) === ['id', 'type', 'title', 'ticket_id', 'ticket_number'];
        });

        $list = $this->fixture('notifications', $this->api($this->technician, 'GET', 'notifications')->assertOk());
        $list->assertJsonPath('data.0.ticket_id', $outage->id)->assertJsonPath('data.0.ticket_number', $outage->ticket_number)->assertJsonPath('data.0.read', false);
        $this->assertGreaterThan(0, $list->json('meta.unread'));
        $this->assertStringNotContainsString('<', $list->json('data.0.body'));
        $this->assertStringNotContainsString("\n\n", $list->json('data.0.body'), 'plain text, one line per line');
        $id = $list->json('data.0.id');

        // Reading it on the phone marks it read (on the web bell too); someone else's notification is "not found".
        $this->api($this->dispatcher, 'POST', "notifications/{$id}/read")->assertNotFound();
        $this->api($this->technician, 'POST', "notifications/{$id}/read")->assertOk()->assertJsonPath('notification.read', true);
        $this->api($this->technician, 'POST', 'notifications/read-all')->assertOk()->assertJsonPath('unread', 0);
        $this->assertSame(0, $this->technician->unreadNotifications()->count());

        // Availability decides whether FIFO offers new tickets.
        $this->api($this->technician, 'POST', 'me/availability', ['status' => 'offline'])->assertOk()->assertJsonPath('user.availability.status', 'offline');
        $this->api($this->technician, 'POST', 'me/availability', ['status' => 'sleeping'])->assertStatus(422);

        // App settings: Reverb for an open app (public connection details, the person's own channel),
        // Firebase for the background / a closed app, and the list filters. Nothing secret.
        $this->fixture('config', $this->api($this->technician, 'GET', 'config'))->assertOk()
            ->assertJsonPath('realtime.user_channel', 'private-App.Models.User.'.$this->technician->id)
            ->assertJsonPath('realtime.event', 'mobile.notification')
            ->assertJsonMissingPath('realtime.secret')
            ->assertJsonPath('push.provider', 'fcm')->assertJsonPath('push.enabled', false)
            ->assertJsonStructure(['filters' => ['scopes', 'modules', 'priorities'], 'uploads' => ['max_kb']]);

        // Signing out removes the device row of that phone.
        $this->api($this->technician, 'POST', 'auth/logout')->assertOk();
        $this->assertSame(0, MobileDevice::query()->where('user_id', $this->technician->id)->count());
    }

    // ------------------------------------------------------------------ Reverb: while the app is open

    public function test_a_person_can_only_listen_to_their_own_realtime_channel(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        // Tests boot with broadcasting off: load the channel rules into the Reverb driver, as production does at boot.
        Broadcast::purge();
        require base_path('routes/channels.php');

        $mine = 'private-App.Models.User.'.$this->technician->id;
        $signature = $this->api($this->technician, 'POST', 'broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $mine])->assertOk()->json('auth');
        $this->assertSame('test-key:'.hash_hmac('sha256', '1234.5678:'.$mine, 'test-secret'), $signature);

        $this->api($this->technician, 'POST', 'broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-App.Models.User.'.$this->dispatcher->id])->assertForbidden();
        $this->api($this->technician, 'POST', 'broadcasting/auth', ['channel_name' => $mine])->assertStatus(422);
    }

    // ------------------------------------------------------------------ Firebase: in the background or closed

    public function test_notifications_are_pushed_through_firebase_to_reach_a_closed_app(): void
    {
        // The Reverb broadcast for an open app is sent alongside; here only the Firebase push is looked at.
        Event::fake([MobileNotification::class]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-access-token', 'expires_in' => 3600]),
            // The first phone accepts the push; the second one's token is no longer registered.
            'fcm.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'projects/oms-test/messages/1'])
                ->push(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                ->push(['name' => 'projects/oms-test/messages/2']),
        ]);

        $register = fn (User $user, string $uuid, string $token) => $this->api($user, 'POST', 'devices', [
            'device_uuid' => $uuid, 'platform' => 'android', 'app_version' => '1.0.0', 'push_provider' => 'fcm', 'push_token' => $token,
        ]);
        $register($this->technician, 'aaaaaaaa-0000-0000-0000-000000000001', 'fcm-token-phone-one')->assertCreated();
        $register($this->technician, 'aaaaaaaa-0000-0000-0000-000000000002', 'fcm-token-old-phone')->assertCreated();
        $register($this->technician, 'aaaaaaaa-0000-0000-0000-000000000003', 'x')->assertCreated();
        $this->api($this->technician, 'POST', 'devices', ['device_uuid' => 'aaaaaaaa-0000-0000-0000-000000000004', 'platform' => 'android', 'push_provider' => 'someone-else', 'push_token' => 't'])
            ->assertStatus(422)->assertJsonValidationErrors('push_provider');
        MobileDevice::query()->where('device_uuid', 'aaaaaaaa-0000-0000-0000-000000000003')->delete();

        // Firebase not set up on the server: nothing is pushed — an open app still gets it over Reverb.
        $this->api($this->technician, 'GET', 'config')->assertJsonPath('push.enabled', false);
        $first = $this->confirmedOutage();
        Auth::setUser($this->dispatcher);
        $this->workflow->dispatch($first, ['team_id' => $this->team->id, 'technician_id' => $this->technician->id], $this->dispatcher);
        Http::assertNothingSent();

        // Set up: the service-account key of the Firebase project, kept on the server.
        // (A throwaway key made for this test. OpenSSL on Windows needs to be handed a config file to make one.)
        $path = storage_path('framework/testing/fcm-'.uniqid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path.'.cnf', "[req]\ndistinguished_name = dn\n[dn]\n");
        $options = ['config' => $path.'.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);
        $this->assertNotFalse($key, 'OpenSSL could not generate a test key');
        openssl_pkey_export($key, $pem, null, $options);
        File::delete($path.'.cnf');
        File::put($path, json_encode(['project_id' => 'oms-test', 'client_email' => 'push@oms-test.iam.gserviceaccount.com', 'private_key' => $pem]));
        config(['oms.mobile.fcm_credentials' => $path]);

        try {
            $this->api($this->technician, 'GET', 'config')->assertJsonPath('push.enabled', true)->assertJsonPath('push.provider', 'fcm');

            $outage = $this->confirmedOutage();
            Auth::setUser($this->dispatcher);
            $this->workflow->dispatch($outage, ['team_id' => $this->team->id, 'technician_id' => $this->technician->id], $this->dispatcher);

            // Google is asked for an access token with a signed request — the key itself never leaves the server.
            Http::assertSent(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com/token')
                && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && count(explode('.', (string) $request['assertion'])) === 3
                && ! str_contains((string) $request->body(), 'PRIVATE KEY'));

            // The push: a title, the ticket number and identifiers — no ticket contents.
            Http::assertSent(function (Request $request) use ($outage): bool {
                if (! str_contains($request->url(), 'fcm.googleapis.com/v1/projects/oms-test/messages:send')) {
                    return false;
                }

                $message = $request['message'];

                return $request->hasHeader('Authorization', 'Bearer google-access-token')
                    && $message['token'] === 'fcm-token-phone-one'
                    && str_contains($message['notification']['title'], $outage->ticket_number)
                    && $message['notification']['body'] === 'Ticket '.$outage->ticket_number
                    && array_keys($message['data']) === ['id', 'type', 'title', 'ticket_id', 'ticket_number']
                    && $message['data']['ticket_id'] === (string) $outage->id
                    && $message['android']['notification']['channel_id'] === 'oms_tickets'
                    && $message['android']['priority'] === 'HIGH';
            });

            // The phone whose token Firebase no longer knows is not pushed to again.
            $this->assertSame('fcm-token-phone-one', MobileDevice::query()->where('device_uuid', 'aaaaaaaa-0000-0000-0000-000000000001')->value('push_token'));
            $this->assertNull(MobileDevice::query()->where('device_uuid', 'aaaaaaaa-0000-0000-0000-000000000002')->value('push_token'));

            // The next notification reuses the access token and goes to the one remaining phone.
            Auth::setUser($this->technician);
            $this->workflow->addUpdate($outage, $this->dispatcher, 'Customer called again', 'progress');
            app(NotificationDispatcher::class)->notifyUsers(NotificationEvent::Assigned, $outage, [$this->technician]);
            Http::assertSentCount(4); // 1 access token + 2 phones + 1 phone

            // Someone else signs in on that phone: its push token now belongs to them alone.
            $register($this->dispatcher, 'aaaaaaaa-0000-0000-0000-000000000001', 'fcm-token-phone-one')->assertCreated();
            $this->assertNull(MobileDevice::query()->where('user_id', $this->technician->id)->where('device_uuid', 'aaaaaaaa-0000-0000-0000-000000000001')->value('push_token'));
            $this->assertSame('fcm-token-phone-one', MobileDevice::query()->where('user_id', $this->dispatcher->id)->value('push_token'));

            // The token is never part of an API answer.
            $this->assertStringNotContainsString('fcm-token', (string) $this->api($this->dispatcher, 'GET', 'me')->getContent());
        } finally {
            File::delete($path);
        }
    }
}
