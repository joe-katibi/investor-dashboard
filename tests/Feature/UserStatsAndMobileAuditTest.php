<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Widgets\UserStats;
use App\Models\AuditLog;
use App\Models\Outage;
use App\Models\OutageAssignmentHistory;
use App\Models\Team;
use App\Models\User;
use App\Services\Outages\OutageWorkflow;
use App\Support\AuditContext;
use App\Support\Settings;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * The figures above the user list (accounts, active / inactive, who signed in on the website and
 * in the mobile app), and the mobile app in the audit trail: sign-in, sign-out and every action,
 * each marked as done from the app.
 */
class UserStatsAndMobileAuditTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private Team $team;

    private User $dispatcher;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->seedReference();
        Mail::fake();
        $this->team = $this->makeTeam('Field A', 'INFRA');
        $this->dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$this->team], ['email' => 'dispatch@example.com']);
        $this->technician = $this->makeUser('Outage Technician', 'INFRA', [$this->team], ['email' => 'tech@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function signIn(User $user, string $password = 'password', array $headers = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withHeaders($headers)->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => $password, 'device_name' => 'Redmi Note 10 Pro']);
    }

    private function api(string $token, string $method, string $uri, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->withHeader('X-App-Version', '1.3.0')->json($method, '/api/v1/'.ltrim($uri, '/'), $data);
    }

    public function test_the_user_list_shows_who_is_active_and_who_signed_in_where(): void
    {
        $admin = $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);
        $inactive = $this->makeUser('CX Agent', 'CX', attributes: ['is_active' => false]);
        $webOnly = $this->makeUser('NOC Tier II', 'NOC');

        // The website and the app each leave their own mark; the old "last login" stays the latest of the two.
        event(new Login('web', $webOnly, false));
        event(new Login('web', $this->dispatcher, false));
        Carbon::setTestNow('2026-10-05 11:00:00');
        $this->signIn($this->dispatcher)->assertOk();
        $this->signIn($this->technician)->assertOk();

        $this->assertSame('2026-10-05 10:00:00', $this->dispatcher->refresh()->last_web_login_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 11:00:00', $this->dispatcher->last_mobile_login_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 11:00:00', $this->dispatcher->last_login_at->format('Y-m-d H:i:s'));
        $this->assertNull($this->technician->refresh()->last_web_login_at, 'only ever in the app');
        $this->assertNull($webOnly->refresh()->last_mobile_login_at);

        $this->assertSame([
            'total' => 5, 'active' => 4, 'inactive' => 1, 'never' => 2, 'web' => 2, 'web_week' => 2, 'mobile' => 2, 'mobile_week' => 2, 'mobile_now' => 0,
        ], UserStats::figures());

        // A phone that registered itself counts as signed in now.
        $token = $this->signIn($this->technician)->json('token');
        $this->api($token, 'POST', 'devices', ['device_uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef0123456789', 'platform' => 'android', 'app_version' => '1.3.0'])->assertSuccessful();
        $this->assertSame(1, UserStats::figures()['mobile_now']);

        // Someone whose last website visit is old still counts as having signed in, not as "this week".
        $webOnly->forceFill(['last_web_login_at' => now()->subDays(30)])->saveQuietly();
        $this->assertSame([2, 1], [UserStats::figures()['web'], UserStats::figures()['web_week']]);

        $this->actingAs($admin);
        Livewire::test(UserStats::class)
            ->assertSee('Total users')->assertSee('Active users')->assertSee('Inactive users')
            ->assertSee('Signed in on the website')->assertSee('Signed in on the mobile app')
            ->assertSee('1 signed in on a phone now');
        Livewire::test(ListUsers::class)->assertOk()->assertSee($inactive->name);
    }

    public function test_sign_in_times_already_on_file_are_split_between_the_website_and_the_app(): void
    {
        $appOnly = $this->makeUser('Outage Technician', 'INFRA', [$this->team]);
        $both = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$this->team]);
        $webOnly = $this->makeUser('NOC Tier II', 'NOC');

        // As it was before: one "last login", and app sign-ins only visible as tokens.
        Schema::table('users', fn ($table) => $table->dropColumn(['last_web_login_at', 'last_mobile_login_at']));
        DB::table('users')->where('id', $appOnly->id)->update(['last_login_at' => '2026-10-01 08:00:05']);
        DB::table('users')->where('id', $both->id)->update(['last_login_at' => '2026-10-04 09:00:00']);
        DB::table('users')->where('id', $webOnly->id)->update(['last_login_at' => '2026-10-02 12:00:00']);
        $token = fn (User $user, string $at) => DB::table('personal_access_tokens')->insert([
            'tokenable_type' => $user->getMorphClass(), 'tokenable_id' => $user->id, 'name' => 'Phone', 'token' => hash('sha256', $user->id.$at),
            'abilities' => '["mobile"]', 'created_at' => $at, 'updated_at' => $at,
        ]);
        $token($appOnly, '2026-10-01 08:00:00');
        $token($both, '2026-09-20 07:00:00');

        (require database_path('migrations/2026_10_10_100001_add_sign_in_channels_to_users.php'))->up();

        $this->assertSame(['2026-10-01 08:00:00', null], [$appOnly->refresh()->last_mobile_login_at?->format('Y-m-d H:i:s'), $appOnly->last_web_login_at?->format('Y-m-d H:i:s')]);
        $this->assertSame(['2026-09-20 07:00:00', '2026-10-04 09:00:00'], [$both->refresh()->last_mobile_login_at->format('Y-m-d H:i:s'), $both->last_web_login_at->format('Y-m-d H:i:s')]);
        $this->assertSame([null, '2026-10-02 12:00:00'], [$webOnly->refresh()->last_mobile_login_at, $webOnly->last_web_login_at->format('Y-m-d H:i:s')]);
        $this->assertNull($this->technician->refresh()->last_web_login_at, 'never signed in: nothing is invented');
    }

    public function test_what_is_done_in_the_app_is_in_the_audit_trail_marked_as_the_mobile_app(): void
    {
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $outage = $this->reportOutage($this->makeUser('CX Agent', 'CX'), ['title' => 'Kira FDT 4 down']);
        Auth::setUser($noc);
        $outage = app(OutageWorkflow::class)->confirmByNoc($outage, $noc);
        $before = AuditLog::query()->max('id');

        // A wrong password on a real account is recorded — never the password itself.
        $this->signIn($this->dispatcher, 'not-the-password', ['X-App-Version' => '1.3.0'])->assertUnprocessable();
        $failed = AuditLog::query()->where('event', 'mobile_login_failed')->sole();
        $this->assertSame([$this->dispatcher->id, null, 'Wrong password', 'Redmi Note 10 Pro'], [$failed->auditable_id, $failed->user_id, $failed->new_values['reason'], $failed->new_values['device']]);
        $this->assertStringNotContainsString('not-the-password', json_encode($failed->getAttributes()));

        // Sign-in.
        $token = $this->signIn($this->dispatcher, headers: ['X-App-Version' => '1.3.0'])->assertOk()->json('token');
        $login = AuditLog::query()->where('event', 'mobile_login')->sole();
        $this->assertSame([$this->dispatcher->id, AuditContext::MOBILE, 'Redmi Note 10 Pro', '1.3.0'], [$login->user_id, $login->trigger, $login->new_values['device'], $login->new_values['app_version']]);
        $this->assertSame('Mobile app: Signed in', $login->eventLabel());

        // An action: one named line for it, and everything it changed carries "mobile" too.
        $this->api($token, 'POST', "tickets/{$outage->id}/actions/dispatch", ['team_id' => $this->team->id, 'technician_id' => $this->technician->id, 'note' => 'Go now'])->assertOk();

        $action = AuditLog::query()->where('event', 'mobile_action')->sole();
        $this->assertSame($outage->id, $action->auditable_id);
        $this->assertSame($this->dispatcher->id, $action->user_id);
        $this->assertSame(AuditContext::MOBILE, $action->trigger);
        $this->assertSame([$outage->ticket_number, 'Dispatch', 'Redmi Note 10 Pro', '1.3.0'], [$action->new_values['ticket'], $action->new_values['action'], $action->new_values['device'], $action->new_values['app_version']]);
        $this->assertSame($outage->ticket_number, $action->subjectLabel());

        $changes = AuditLog::query()->where('id', '>', $before)->where('auditable_type', (new Outage)->getMorphClass())->where('event', 'updated')->get();
        $this->assertNotEmpty($changes);
        $this->assertSame([AuditContext::MOBILE], $changes->pluck('trigger')->unique()->values()->all());
        $this->assertSame(AuditContext::MOBILE, $outage->statusHistories()->first()->trigger, 'the status history says so too');
        $this->assertTrue(OutageAssignmentHistory::query()->where('outage_id', $outage->id)->where('queue_type', QueueType::Technician->value)->where('trigger', AuditContext::MOBILE)->exists());

        // Raising maintenance from the app.
        $techToken = $this->signIn($this->technician)->json('token');
        app(Settings::class)->set(['photos.maintenance_required' => false]);
        $raised = $this->api($techToken, 'POST', 'actions/raise_corrective', ['title' => 'Pole down', 'description' => 'Drop cables cut', 'site_label' => 'FAT 12'])->assertOk();
        $entry = AuditLog::query()->where('event', 'mobile_raised')->sole();
        $this->assertSame([$raised->json('ticket.id'), $this->technician->id, 'Raise corrective'], [$entry->auditable_id, $entry->user_id, $entry->new_values['action']]);

        // Sign-out.
        $this->api($token, 'POST', 'auth/logout')->assertOk();
        $logout = AuditLog::query()->where('event', 'mobile_logout')->sole();
        $this->assertSame([$this->dispatcher->id, AuditContext::MOBILE], [$logout->user_id, $logout->trigger]);

        // The website is not marked as the app, and the next thing done there is "Website" again.
        Auth::setUser($noc);
        app(OutageWorkflow::class)->addUpdate($outage->refresh(), $noc, 'Seen from the website');
        $this->assertNotSame(AuditContext::MOBILE, AuditContext::trigger(), 'the mark does not outlive the app\'s request');
        $this->assertNotSame(AuditContext::MOBILE, $outage->updates()->first()->outage->auditLogs()->first()->trigger);
        $this->assertSame(['Mobile app', 'Website', 'System (sla-check)'], [AuditContext::label('mobile'), AuditContext::label('user'), AuditContext::label('system:sla-check')]);

        // The audit screen: filter on what was done from the app.
        $this->actingAs($this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']));
        Livewire::test(ManageAuditLogs::class)
            ->filterTable('source', 'mobile')
            ->assertCountTableRecords(AuditLog::query()->where('trigger', AuditContext::MOBILE)->count())
            // …narrowed to the named entries (the changes each action made fill the first page otherwise).
            ->filterTable('event', ['mobile_login', 'mobile_action', 'mobile_raised', 'mobile_logout'])
            ->assertCanSeeTableRecords([$login, $action, $logout, $entry])
            ->assertCanNotSeeTableRecords(AuditLog::query()->where('trigger', '!=', AuditContext::MOBILE)->limit(3)->get())
            ->assertSee('Mobile app: Action on a ticket');
    }
}
