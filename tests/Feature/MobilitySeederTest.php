<?php

namespace Tests\Feature;

use App\Enums\StatusCode;
use App\Models\Department;
use App\Models\EmailTemplate;
use App\Models\Enodeb;
use App\Models\ImpactRule;
use App\Models\MajorOutageType;
use App\Models\NotificationRule;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\QueueDefinition;
use App\Models\SlaPolicy;
use App\Models\TransmissionNode;
use App\Models\Vendor;
use App\Models\VendorTemplate;
use App\Models\Vertical;
use App\Services\Outages\OutageWorkflow;
use App\Services\Outages\PriorityResolver;
use Database\Seeders\MobilitySeeder;
use Database\Seeders\MobilityTopologySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * `php artisan db:seed --class=MobilitySeeder`: adds the 4G NOC module to a production system that
 * already holds tuned FTTH data, creating only what is missing and updating nothing.
 */
class MobilitySeederTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    /** Reference tables whose existing rows must come out byte-for-byte the same. */
    private const TABLES = [
        'verticals', 'services', 'departments', 'major_outage_types', 'outage_types', 'outage_statuses', 'priorities',
        'sla_policies', 'queue_definitions', 'notification_rules', 'email_templates', 'maintenance_templates', 'settings',
    ];

    protected function tearDown(): void
    {
        MobilityTopologySeeder::$paths = null;
        parent::tearDown();
    }

    public function test_it_adds_the_4g_module_without_touching_existing_data(): void
    {
        $this->seedReference();
        Mail::fake();
        $this->stripMobility();

        // A live system: the FTTH side has been tuned, and someone made their own "Open" status.
        Priority::query()->where('code', 'high')->update(['name' => 'High (tuned)', 'min_customers' => 75]);
        OutageStatus::query()->where('code', StatusCode::NocConfirmed->value)->update(['name' => 'Confirmed by NOC', 'color' => 'success']);
        OutageStatus::query()->create(['name' => 'Open', 'color' => 'gray', 'is_manual_selectable' => true, 'is_active' => true]);
        OutageType::query()->where('code', 'regular')->update(['name' => 'Standard Outage']);
        MajorOutageType::query()->where('code', MajorOutageType::NETWORK)->update(['description' => 'Our wording']);
        Department::query()->where('code', 'NOC')->update(['name' => 'Network Operations']);
        QueueDefinition::query()->where('queue_type', 'noc_review')->update(['ack_timeout_minutes' => 99]);
        SlaPolicy::query()->where('name', 'High – 8h')->update(['resolution_minutes' => 300]);
        NotificationRule::query()->where('name', 'Ticket created – NOC')->update(['channels' => json_encode(['database'])]);
        EmailTemplate::query()->where('code', 'two_hour_update')->update(['subject' => 'Our subject', 'is_active' => true]);
        Role::findByName('NOC Tier I')->revokePermissionTo('outage.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $before = $this->snapshot();
        $grantsBefore = $this->grants();
        $this->useTopology();

        $this->seed(MobilitySeeder::class);
        PriorityResolver::flush();

        // Every row that was there is exactly as it was.
        foreach ($this->snapshot() as $table => $rows) {
            foreach ($before[$table] as $id => $row) {
                $this->assertEquals($row, $rows[$id] ?? null, "{$table} #{$id} was changed");
            }
        }

        // Roles: nothing regranted, nothing changed — only the 4G roles are new (and Executive may view 4G).
        $grants = $this->grants();
        foreach ($grantsBefore as $role => $permissions) {
            $this->assertEqualsCanonicalizing($role === 'Executive' ? [...$permissions, 'mobility.view'] : $permissions, $grants[$role], $role);
        }
        $this->assertNotContains('outage.create', $grants['NOC Tier I']);
        $this->assertEqualsCanonicalizing([...array_keys($grantsBefore), ...RolesAndPermissionsSeeder::MOBILITY_ROLES], array_keys($grants));
        $this->assertContains('mobility.verify', $grants['4G NOC']);
        // …with the two 4G screens that come with "View 4G outages" (each can be unticked per role).
        $screens = ['mobility.dashboard', 'mobility.engineers'];
        $this->assertEqualsCanonicalizing(['mobility.view', 'mobility.work', 'queue.work_mobility_engineer', 'mobility.handle_transmission', ...$screens], $grants['4G Engineer']);
        $this->assertEqualsCanonicalizing(['mobility.view', 'mobility.work', 'queue.work_mobility_engineer', 'mobility.handle_enodeb', ...$screens], $grants['4G Technician']);
        $this->assertNotContains('mobility.handle_enodeb', $grants['4G NOC Manager'], 'the NOC assigns, it is not handed tickets to fix');

        // The 4G module is complete.
        $vertical = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');
        $this->assertSame(3, OutageType::query()->mobility()->count());
        $this->assertSame(13, OutageStatus::query()->mobility()->count());
        $this->assertSame('4G Open', OutageStatus::query()->where('code', StatusCode::MobilityOpen->value)->value('name'), 'the existing custom "Open" keeps its name');
        $this->assertSame(3, Priority::query()->where('vertical_id', $vertical)->count());
        $this->assertSame(16, ImpactRule::query()->count());
        $this->assertSame(3, SlaPolicy::query()->where('vertical_id', $vertical)->count());
        $this->assertSame(2, QueueDefinition::query()->whereIn('queue_type', ['mobility_engineer', 'mobility_noc'])->count());
        $this->assertGreaterThan(15, NotificationRule::query()->where('vertical_id', $vertical)->count());
        $this->assertFalse(EmailTemplate::query()->where('code', 'mobility_hourly_update')->firstOrFail()->is_active);
        $this->assertSame(['ATC', 'TOA', 'UTEL'], Vendor::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(5, VendorTemplate::query()->count());
        $this->assertSame(2, Enodeb::query()->count());
        $this->assertSame(Vendor::query()->where('code', 'ATC')->value('id'), Enodeb::query()->where('site_id', '100')->value('vendor_id'));
        $this->assertSame(['ASG-91', 'CSG-911', 'RSG-9', 'SSG-9111', 'SSG-9112'], TransmissionNode::query()->orderBy('code')->pluck('code')->all());

        // …and works end to end.
        $noc = $this->makeUser('4G NOC', 'MOBILITY');
        Auth::setUser($noc);
        $ticket = app(OutageWorkflow::class)->createMobility([
            'outage_type_id' => OutageType::query()->where('code', 'mobility_power')->value('id'),
            'title' => 'Alpha — mains failure',
            'enodeb_ids' => [Enodeb::query()->where('site_id', '100')->value('id')],
            'vendor_id' => tap(Vendor::query()->where('code', 'ATC')->first())->update(['email' => 'ops@atc.example'])->id,
        ], $noc);
        $this->assertSame('4G-PWR-000001', $ticket->ticket_number);
        $this->assertSame(Priority::MOBILITY_MINOR, $ticket->priority->code);
        $this->assertTrue($ticket->status->isCode(StatusCode::MobilityVendorNotified));

        // Running it again changes nothing — including what was edited on the 4G side meanwhile.
        ImpactRule::query()->where('name', 'Power fault – 1 to 3 eNodeBs')->update(['max_value' => 2]);
        VendorTemplate::query()->where('code', 'power_vendor_notification')->update(['subject' => 'Our vendor subject']);
        OutageStatus::query()->where('code', StatusCode::MobilityMonitoring->value)->update(['name' => 'Under observation', 'pauses_sla' => true]);
        Role::findByName('4G Engineer')->revokePermissionTo('mobility.work');
        $again = $this->snapshot() + ['impact_rules' => $this->rows('impact_rules'), 'vendors' => $this->rows('vendors'), 'vendor_templates' => $this->rows('vendor_templates'),
            'enodebs' => $this->rows('enodebs'), 'transmission_nodes' => $this->rows('transmission_nodes')];
        $grantsAgain = $this->grants();

        $this->seed(MobilitySeeder::class);

        foreach ($again as $table => $rows) {
            $this->assertEquals($rows, $this->rows($table), "{$table} changed on the second run");
        }
        $this->assertSame($grantsAgain, $this->grants());
    }

    public function test_a_running_system_gets_the_engineer_technician_split_from_the_migration(): void
    {
        $this->seedReference();

        // How production looks before this release: everyone in the 4G queue receives every 4G ticket.
        $handlers = ['mobility.handle_transmission', 'mobility.handle_enodeb'];
        Permission::query()->whereIn('name', $handlers)->get()->each->delete();
        Role::findOrCreate('4G Field Lead', 'web')->givePermissionTo('queue.work_mobility_engineer');
        $direct = $this->makeUser('CX Agent', 'CX');
        $direct->givePermissionTo('queue.work_mobility_engineer');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_10_03_200001_split_mobility_ticket_handlers.php');
        $migration->up();
        $migration->up(); // running it twice changes nothing

        $handles = fn (string $role) => array_values(array_intersect($this->grants()[$role], $handlers));
        $this->assertSame(['mobility.handle_transmission'], $handles('4G Engineer'));
        $this->assertSame(['mobility.handle_enodeb'], $handles('4G Technician'));
        $this->assertEqualsCanonicalizing($handlers, $handles('4G Field Lead'), 'a role of your own keeps receiving both, as before');
        $this->assertSame([], $handles('4G NOC'));
        $this->assertEqualsCanonicalizing($handlers, array_values(array_intersect($direct->refresh()->getDirectPermissions()->pluck('name')->all(), $handlers)));
    }

    // ------------------------------------------------------------------ helpers

    /** Take the freshly seeded database back to how production looked before the 4G module. */
    private function stripMobility(): void
    {
        $vertical = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');

        ImpactRule::query()->delete();
        VendorTemplate::query()->delete();
        Vendor::query()->delete();
        SlaPolicy::query()->where('vertical_id', $vertical)->delete();
        NotificationRule::query()->where('vertical_id', $vertical)->delete();
        EmailTemplate::query()->where('code', 'mobility_hourly_update')->delete();
        QueueDefinition::query()->whereIn('queue_type', ['mobility_engineer', 'mobility_noc'])->delete();
        Priority::query()->where('vertical_id', $vertical)->delete();
        OutageStatus::query()->mobility()->delete();
        OutageType::query()->mobility()->delete();
        MajorOutageType::query()->where('code', MajorOutageType::MOBILITY)->delete();
        Department::query()->where('code', 'MOBILITY')->delete();
        Role::query()->whereIn('name', RolesAndPermissionsSeeder::MOBILITY_ROLES)->get()->each->delete();
        Permission::query()->where('name', 'like', 'mobility.%')->orWhere('name', 'like', '%work_mobility_%')->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        PriorityResolver::flush();
    }

    /** A two-site workbook instead of the bundled one, to keep the test quick. */
    private function useTopology(): void
    {
        $sites = tempnam(sys_get_temp_dir(), 'oms').'.csv';
        $nodes = tempnam(sys_get_temp_dir(), 'oms').'.csv';
        file_put_contents($sites, "SAVANNA ID,SAVANNA SITE NAME,Tower CO ID,Latitude,Longitude,Owner\n100,Alpha,A1,0.31,32.58,ATC\n200,Bravo,T2,0.32,32.59,TOA\n");
        file_put_contents($nodes, "Type,Scripts,Gateway,CSG NAME,Hostname,Management IP,SITE NAME,SAV GO SITE ID,TOWER CO ID,TOWER CO,LABEL STATUS\n"
            ."SSG,baicell-SSG,10.0.0.14,Alpha ring csg-911,ssg-9111-ebb-ran,10.0.0.1,Alpha,100,A1,ATC,\n"
            ."SSG,baicell-SSG,,,ssg-9112-ebb-ran,10.0.0.2,Bravo,200,T2,TOA,\n");

        MobilityTopologySeeder::$paths = [$sites, $nodes];
    }

    /** @return array<string, array<int, array<string, mixed>>> table => id => row */
    private function snapshot(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => $this->rows($table)])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->keyBy('id')->map(fn ($row) => (array) $row)->all();
    }

    /** @return array<string, list<string>> role => sorted permission names */
    private function grants(): array
    {
        return Role::query()->with('permissions:id,name')->orderBy('name')->get()
            ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])->all();
    }
}
