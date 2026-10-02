<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\MajorOutageType;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

/**
 * Everything the 4G NOC module needs, and nothing else — for a production system that already
 * holds configured FTTH data:
 *
 *   php artisan db:seed --class=MobilitySeeder --force
 *
 * It only CREATES what is missing. No existing row is updated: FTTH priorities, statuses, outage
 * types, queues, SLA policies, notification rules, e-mail templates, departments and role
 * permissions stay exactly as they are. Safe to run again (nothing is duplicated, and 4G records
 * edited in the meantime are left alone).
 *
 * Creates: the 4G permissions and roles · the "4G NOC" department · the 4G major type, outage types
 * (eNodeB / Transmission / Power) and statuses · the 4G severity levels and severity rules · 4G SLA
 * policies · the two 4G queues · 4G notification rules · the hourly 4G e-mail template · vendors
 * (ATC, TOA, UTEL) and the vendor e-mail templates · eNodeB sites and transmission nodes.
 *
 * "4G Settings & Alerts" needs no seeding: it starts from built-in defaults until saved on screen.
 */
class MobilitySeeder extends Seeder
{
    public function run(): void
    {
        app(RolesAndPermissionsSeeder::class)->mobility();

        // Names are unique: if one is already taken by a record of your own, the new one gets a suffix.
        $free = fn (string $model, string $name) => $model::query()->where('name', $name)->exists() ? $name.' (4G module)' : $name;

        Vertical::query()->firstOrCreate(['code' => Vertical::MOBILITY_4G], ['name' => $free(Vertical::class, '4G Mobility'), 'description' => '4G / LTE mobile network', 'is_active' => true]);
        Department::query()->firstOrCreate(['code' => 'MOBILITY'], [
            'name' => $free(Department::class, '4G NOC'), 'description' => '4G network operations: 4G NOC, engineers and technicians', 'is_active' => true,
        ]);
        MajorOutageType::query()->firstOrCreate(['code' => MajorOutageType::MOBILITY], [
            'name' => $free(MajorOutageType::class, '4G Network Outage'),
            'description' => '4G NOC: eNodeB, transmission and power outages',
            'requires_noc_confirmation' => false,
            'requires_infrastructure' => false,
            'uses_topology' => false,
            'requires_it_review' => false,
            'sort_order' => 4,
            'is_active' => true,
        ]);

        // In dependency order: types and levels before the rules and policies that point at them.
        app(OutageTypeSeeder::class)->mobility();
        app(OutageStatusSeeder::class)->mobility();
        app(PrioritySeeder::class)->mobility();
        app(SlaPolicySeeder::class)->mobility();
        app(QueueDefinitionSeeder::class)->mobility();
        app(NotificationRuleSeeder::class)->mobility();
        app(EmailTemplateSeeder::class)->mobility();

        // These three are create-only already; vendors come before the sites that link to them.
        $this->call([
            ImpactRuleSeeder::class,
            VendorSeeder::class,
            VendorTemplateSeeder::class,
            MobilityTopologySeeder::class,
        ]);
    }
}
