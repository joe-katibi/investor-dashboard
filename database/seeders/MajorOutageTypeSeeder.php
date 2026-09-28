<?php

namespace Database\Seeders;

use App\Models\MajorOutageType;
use Illuminate\Database\Seeder;

/** Major outage types (spec §5): Network, Core, System. */
class MajorOutageTypeSeeder extends Seeder
{
    public function run(): void
    {
        // [code, name, requires NOC confirmation, requires Infrastructure, uses topology, IT reviews first, sort, description]
        foreach ([
            [MajorOutageType::NETWORK, 'Network Outage', true, true, true, false, 1, 'FTTH / 4G network outages'],
            [MajorOutageType::CORE, 'Core Outage', true, true, false, false, 2, 'SEACOM and other core service outages'],
            // IT checks first; escalates to NOC only when the cause is core / network.
            [MajorOutageType::SYSTEM, 'System Outage', false, false, false, true, 3, 'CRM, Call Center, Dialer and other systems'],
        ] as [$code, $name, $noc, $infra, $topology, $it, $sort, $description]) {
            MajorOutageType::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'requires_noc_confirmation' => $noc,
                'requires_infrastructure' => $infra,
                'uses_topology' => $topology,
                'requires_it_review' => $it,
                'sort_order' => $sort,
                'description' => $description,
                'is_active' => true,
            ]);
        }
    }
}
