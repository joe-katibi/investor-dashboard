<?php

namespace Database\Seeders;

use App\Models\MajorOutageType;
use Illuminate\Database\Seeder;

/** Major outage types (spec §5): Network, Core, System. */
class MajorOutageTypeSeeder extends Seeder
{
    public function run(): void
    {
        // [code, name, requires NOC confirmation, requires Infrastructure, uses topology, sort, description]
        foreach ([
            [MajorOutageType::NETWORK, 'Network Outage', true, true, true, 1, 'FTTH / 4G network outages'],
            [MajorOutageType::CORE, 'Core Outage', true, true, false, 2, 'SEACOM and other core service outages'],
            [MajorOutageType::SYSTEM, 'System Outage', true, false, false, 3, 'CRM, Call Center, Dialer and other systems'],
        ] as [$code, $name, $noc, $infra, $topology, $sort, $description]) {
            MajorOutageType::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'requires_noc_confirmation' => $noc,
                'requires_infrastructure' => $infra,
                'uses_topology' => $topology,
                'sort_order' => $sort,
                'description' => $description,
                'is_active' => true,
            ]);
        }
    }
}
