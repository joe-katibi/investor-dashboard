<?php

namespace Tests\Feature;

use App\Filament\Pages\Reports;
use App\Filament\Resources\Outages\OutageResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Outages\OutageWorkflow;
use App\Support\AuditContext;
use App\Support\Permissions;
use App\Support\Settings;
use Filament\Auth\Pages\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * What used to leave no trace is on the audit trail: signing in and out on the website and
 * failed attempts, password changes, who was given which role and team, which permissions a role
 * holds, and reports taken out of the system. Passwords themselves are never recorded.
 */
class ActivityAuditTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
    }

    private function entries(string $event)
    {
        return AuditLog::query()->where('event', $event)->orderBy('id')->get();
    }

    public function test_signing_in_and_out_on_the_website_and_failed_attempts_are_recorded(): void
    {
        $user = $this->makeUser('NOC Tier II', 'NOC', attributes: ['email' => 'noc@example.com', 'password' => Hash::make('Correct-Horse-9!')]);

        // A wrong password, an address nobody has, then the right one.
        Livewire::test(Login::class)->fillForm(['email' => 'noc@example.com', 'password' => 'wrong-password'])->call('authenticate');
        Livewire::test(Login::class)->fillForm(['email' => 'nobody@example.com', 'password' => 'whatever-it-is'])->call('authenticate');

        $failed = $this->entries('auth_login_failed');
        $this->assertSame([[$user->id, 'Wrong password'], [0, 'No such account']], $failed->map(fn (AuditLog $log) => [$log->auditable_id, $log->new_values['reason']])->all());
        $this->assertSame('nobody@example.com', $failed[1]->new_values['login']);
        $this->assertNull($failed[0]->user_id, 'nobody was signed in');
        $this->assertStringNotContainsString('wrong-password', $failed->toJson());
        $this->assertStringNotContainsString('whatever-it-is', $failed->toJson());
        $this->assertSame('Website: Sign-in failed', $failed[0]->eventLabel());

        Livewire::test(Login::class)->fillForm(['email' => 'noc@example.com', 'password' => 'Correct-Horse-9!'])->call('authenticate')->assertHasNoFormErrors();
        $login = $this->entries('auth_login')->sole();
        $this->assertSame([$user->id, $user->id, 'Website: Signed in'], [$login->auditable_id, $login->user_id, $login->eventLabel()]);
        $this->assertNotSame(AuditContext::MOBILE, $login->trigger);

        Auth::guard('web')->logout();
        $this->assertSame($user->id, $this->entries('auth_logout')->sole()->user_id);
    }

    public function test_a_password_change_is_recorded_without_the_password(): void
    {
        $admin = $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);
        $user = $this->makeUser('CX Agent', 'CX');

        // Changed by an administrator…
        $this->actingAs($admin);
        $user->update(['password' => Hash::make('New-Secret-Value-77!')]);
        $changed = $this->entries('auth_password_changed')->sole();
        $this->assertSame([$user->id, $admin->id, $admin->name], [$changed->auditable_id, $changed->user_id, $changed->new_values['by']]);
        $this->assertStringNotContainsString('New-Secret', $changed->toJson());
        $this->assertStringNotContainsString('$2y$', AuditLog::query()->where('auditable_id', $user->id)->get()->toJson(), 'no hash either');

        // …by the person themselves, and through the e-mailed reset link.
        $this->actingAs($user);
        $user->update(['password' => Hash::make('Another-Secret-88!')]);
        $this->assertSame('The person themselves', $this->entries('auth_password_changed')->last()->new_values['by']);
        event(new PasswordReset($user));
        $this->assertSame('Password reset by e-mail link', $this->entries('auth_password_reset')->sole()->eventLabel());

        // Any other change of the account does not claim the password changed.
        $user->update(['phone' => '0700123456']);
        $this->assertCount(2, $this->entries('auth_password_changed'));
    }

    public function test_roles_teams_and_the_permissions_of_a_role_are_recorded_with_who_changed_them(): void
    {
        $admin = $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);
        $team = $this->makeTeam('Field A', 'INFRA');
        $user = $this->makeUser('CX Agent', 'CX', attributes: ['username' => 'agent-one']);
        $this->actingAs($admin);

        // A person is given another role and a team on the user screen.
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm([
                'roles' => [Role::findByName('CX Agent')->id, Role::findByName('CX Tier II')->id],
                'teams' => [$team->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $roles = $this->entries('roles_changed')->sole();
        $this->assertSame([$user->id, $admin->id, 'CX Tier II', 'CX Agent, CX Tier II'], [$roles->auditable_id, $roles->user_id, $roles->new_values['added'], $roles->new_values['now']]);
        $this->assertSame('CX Agent', $roles->old_values['roles']);
        $this->assertSame(['Field A', 'none'], [$this->entries('teams_changed')->sole()->new_values['added'], $this->entries('teams_changed')->sole()->old_values['teams']]);

        // Saving again without changing them adds nothing.
        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])->call('save')->assertHasNoFormErrors();
        $this->assertCount(1, $this->entries('roles_changed'));

        // A permission is taken from a role and another given.
        $role = Role::findByName('CX Agent');
        $state = collect(Permissions::GROUPS)->mapWithKeys(fn (array $permissions, string $group) => [
            'permissions_'.str($group)->slug('_') => $role->permissions->pluck('name')->intersect(array_keys($permissions))->values()->all(),
        ])->all();
        $state['permissions_outages'] = array_values(array_diff($state['permissions_outages'], ['outage.update_credit_sms']));
        $state['permissions_reporting_audit'][] = 'reports.view';
        RoleResource::syncPermissions($role, $state);

        $permissions = $this->entries('permissions_changed')->sole();
        $this->assertSame([$role->id, $admin->id, 'CX Agent', 'reports.view', 'outage.update_credit_sms'], [
            $permissions->auditable_id, $permissions->user_id, $permissions->new_values['role'], $permissions->new_values['added'], $permissions->new_values['removed'],
        ]);
        $this->assertSame('Permissions of a role changed', $permissions->eventLabel());

        RoleResource::syncPermissions($role->refresh(), collect($state)->all());
        $this->assertCount(1, $this->entries('permissions_changed'), 'nothing changed, nothing recorded');
    }

    public function test_taking_a_report_out_of_the_system_is_recorded(): void
    {
        $manager = $this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']);
        $this->actingAs($manager);

        Livewire::test(Reports::class)
            ->set('data.report', 'outage_register')
            ->set('data.from', '2026-10-01')
            ->set('data.to', '2026-10-05')
            ->call('export', 'csv');

        $export = $this->entries('report_exported')->sole();
        $this->assertSame([$manager->id, 'Outage Register', 'CSV', '2026-10-01', '2026-10-05'], [
            $export->user_id, $export->new_values['report'], $export->new_values['format'], $export->new_values['from'], $export->new_values['to'],
        ]);
        $this->assertSame('Report exported', $export->eventLabel());

        // Someone who may not export leaves no entry (and gets no file).
        $this->actingAs($this->makeUser('NOC Tier I', 'NOC', attributes: ['availability_status' => 'offline']));
        try {
            Livewire::test(Reports::class)->set('data.report', 'outage_register')->call('export', 'csv');
        } catch (\Throwable) {
        }
        $this->assertCount(1, $this->entries('report_exported'));
    }

    public function test_opening_a_ticket_is_recorded_once_per_visit_on_the_website_and_in_the_app(): void
    {
        $agent = $this->makeUser('CX Agent', 'CX');
        $team = $this->makeTeam('Field A', 'INFRA');
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $technician = $this->makeUser('Outage Technician', 'INFRA', [$team], ['email' => 'tech@example.com']);
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $outage = $this->reportOutage($agent, ['title' => 'Kira FDT 4 down']);

        // The NOC user opens the ticket page, reloads it, and comes back twenty minutes later.
        $this->actingAs($noc);
        $this->get(OutageResource::getUrl('view', ['record' => $outage]))->assertOk();
        $this->get(OutageResource::getUrl('view', ['record' => $outage]))->assertOk();

        $opened = $this->entries('viewed');
        $this->assertCount(1, $opened, 'a reload is not a new visit');
        $this->assertSame([$outage->id, $noc->id, $outage->ticket_number, 'Ticket opened'], [
            $opened[0]->auditable_id, $opened[0]->user_id, $opened[0]->new_values['ticket'], $opened[0]->eventLabel(),
        ]);
        $this->assertSame($outage->ticket_number, $opened[0]->subjectLabel());

        $this->travel(20)->minutes();
        $this->get(OutageResource::getUrl('view', ['record' => $outage]))->assertOk();
        $this->assertCount(2, $this->entries('viewed'));

        // Somebody else opening it is their own entry; someone who may not see it leaves none.
        $this->actingAs($agent);
        $this->get(OutageResource::getUrl('view', ['record' => $outage]))->assertOk();
        $this->assertSame([$noc->id, $noc->id, $agent->id], $this->entries('viewed')->pluck('user_id')->all());

        $this->actingAs($this->makeUser('4G Engineer', 'MOBILITY'));
        $this->get(OutageResource::getUrl('view', ['record' => $outage]))->assertForbidden();
        $this->assertCount(3, $this->entries('viewed'));

        // In the mobile app the same, marked as done from the app.
        Auth::setUser($noc);
        $outage = app(OutageWorkflow::class)->confirmByNoc($outage, $noc);
        Auth::setUser($dispatcher);
        app(OutageWorkflow::class)->dispatch($outage, ['team_id' => $team->id, 'technician_id' => $technician->id], $dispatcher);

        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['login' => 'tech@example.com', 'password' => 'password', 'device_name' => 'Phone'])->assertOk()->json('token');
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/tickets/{$outage->id}")->assertOk();
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/tickets/{$outage->id}")->assertOk();

        $fromApp = $this->entries('viewed')->where('user_id', $technician->id);
        $this->assertCount(1, $fromApp);
        $this->assertSame(AuditContext::MOBILE, $fromApp->first()->trigger);

        // Switched off: nothing more is recorded for reading.
        app(Settings::class)->set(['audit.ticket_views' => false]);
        $this->travel(30)->minutes();
        $this->flushHeaders()->actingAs($noc, 'web'); // back on the website (the app's requests left its token guard as the default)
        $this->get(OutageResource::getUrl('view', ['record' => $outage]))->assertOk();
        $this->assertCount(4, $this->entries('viewed'));
    }
}
