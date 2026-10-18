<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MobilityDashboard;
use App\Filament\Pages\MobilityEngineers;
use App\Filament\Pages\TicketDashboard;
use App\Filament\Resources\SiteVisits\SiteVisitResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Dashboards, the site visits list and the 4G engineers screen each have a permission of their
 * own, so a role can keep working tickets without seeing every screen of the module.
 */
class ScreenPermissionsTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    public function test_roles_that_view_a_module_have_its_screens_by_default(): void
    {
        $this->actingAs($this->makeUser('CX Agent', 'CX'));

        $this->assertTrue(Dashboard::canAccess());
        $this->assertTrue(TicketDashboard::canAccess());
        $this->assertTrue(SiteVisitResource::canViewAny());
        $this->assertFalse(MobilityDashboard::canAccess(), 'a CX agent has nothing to do with the 4G NOC');
        $this->assertFalse(MobilityEngineers::canAccess());

        $this->actingAs($this->makeUser('4G NOC', 'MOBILITY'));
        $this->assertTrue(MobilityDashboard::canAccess());
        $this->assertTrue(MobilityEngineers::canAccess());
        $this->assertFalse(TicketDashboard::canAccess());
    }

    public function test_a_screen_can_be_hidden_from_a_role_without_taking_the_work_away(): void
    {
        Role::findByName('CX Agent')->revokePermissionTo(['dashboard.view', 'ticket.dashboard', 'visit.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->actingAs($agent);

        // The Command Centre is out of their menu; signing in there sends them on to their own page.
        $this->assertFalse(Dashboard::shouldRegisterNavigation());
        $this->assertStringNotContainsString('Command Centre', $this->followingRedirects()->get(Dashboard::getUrl())->assertOk()->getContent());
        $this->assertFalse(TicketDashboard::canAccess());
        $this->assertFalse(SiteVisitResource::canViewAny());
        $this->get(TicketDashboard::getUrl())->assertForbidden();
        $this->get(SiteVisitResource::getUrl('index'))->assertForbidden();

        // A visit they are sent to still opens; it is only the list that is gone.
        $visit = app(OutageWorkflow::class)->createSiteVisit([
            'visit_type_id' => VisitType::query()->value('id'), 'account_number' => 'SF2577916', 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001',
        ], $this->makeUser('Site Visit Dispatcher', 'DISPATCH', attributes: ['availability_status' => 'offline']));
        $this->actingAs($agent);
        $this->get(SiteVisitResource::getUrl('view', ['record' => $visit]))->assertOk();
        $this->assertFalse(SiteVisitResource::canGloballySearch());

        // They still raise and follow customer tickets and report outages.
        $this->assertTrue(TicketResource::canViewAny());
        $this->assertTrue($agent->can('ticket.create'));
        $this->assertTrue($agent->can('outage.create'));
        $this->get(TicketResource::getUrl('index'))->assertOk();
    }

    public function test_an_installation_in_use_keeps_every_screen_when_the_switches_arrive(): void
    {
        // As before the upgrade: the screen permissions do not exist, roles only have the module permissions.
        Permission::query()->whereIn('name', array_keys(Permissions::SCREENS))->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $direct = $this->makeUser('IT Engineer', 'IT');
        $direct->givePermissionTo('ticket.view');
        $this->assertFalse(Role::findByName('NOC Manager')->hasPermissionTo('outage.view') && Permission::query()->where('name', 'dashboard.view')->exists());

        $migration = require database_path('migrations/2026_10_09_100003_add_screen_permissions.php');
        $migration->up();

        $this->assertTrue(Role::findByName('NOC Manager')->hasPermissionTo('dashboard.view'));
        $this->assertTrue(Role::findByName('CX Agent')->hasPermissionTo('ticket.dashboard'));
        $this->assertTrue(Role::findByName('Site Visit Technician')->hasPermissionTo('visit.view'));
        $this->assertTrue(Role::findByName('4G NOC')->hasPermissionTo('mobility.engineers'));
        $this->assertFalse(Role::findByName('4G Engineer')->hasPermissionTo('ticket.dashboard'), 'no screen of a module the role cannot view');
        $this->assertTrue($direct->refresh()->hasDirectPermission('visit.view'), 'a permission given to a person directly is followed too');

        // The role screen lists them with the other permissions.
        $this->assertSame([], array_diff(array_keys(Permissions::SCREENS), Permissions::all()));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
    }
}
