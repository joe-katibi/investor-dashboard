<?php

namespace Database\Seeders;

use App\Models\ImpactRule;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

/**
 * 4G fault severity rules, as given by the 4G NOC. When several rules match a ticket the most
 * severe one wins, so the overlapping ranges of the specification ("1–3" and "3–6") resolve
 * upwards: exactly 3 eNodeBs is Major.
 *
 * Created only when missing: rules edited under 4G NOC → 4G Severity Rules survive re-seeding.
 * Requires PrioritySeeder and OutageTypeSeeder.
 */
class ImpactRuleSeeder extends Seeder
{
    public function run(): void
    {
        $vertical = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');
        $type = OutageType::query()->pluck('id', 'code');
        $priority = Priority::query()->pluck('id', 'code');

        [$minor, $major, $critical] = [Priority::MOBILITY_MINOR, Priority::MOBILITY_MAJOR, Priority::MOBILITY_CRITICAL];

        // [name, outage type, metric, from, to, priority]
        foreach ([
            // 1. eNodeB faults: one cell, several cells or one eNodeB down are all Minor.
            ['eNodeB fault – cells down', 'mobility_enodeb', 'cells', 1, null, $minor],
            ['eNodeB fault – eNodeB down', 'mobility_enodeb', 'enodebs', 1, null, $minor],

            // 2. Transmission node / link failures.
            ['Transmission fault – 1 to 3 eNodeBs', 'mobility_transmission', 'enodebs', 1, 3, $minor],
            ['Transmission fault – 3 to 6 eNodeBs', 'mobility_transmission', 'enodebs', 3, 6, $major],
            ['Transmission fault – more than 6 eNodeBs', 'mobility_transmission', 'enodebs', 7, null, $critical],
            ['Transmission fault – 1 to 2 SSGs', 'mobility_transmission', 'ssg', 1, 2, $major],
            ['Transmission fault – 3 or more SSGs', 'mobility_transmission', 'ssg', 3, null, $critical],
            ['Transmission fault – 1 or more ASGs', 'mobility_transmission', 'asg', 1, null, $critical],
            ['Transmission fault – 1 or more RSGs', 'mobility_transmission', 'rsg', 1, null, $critical],

            // 3. Power failures.
            ['Power fault – 1 to 3 eNodeBs', 'mobility_power', 'enodebs', 1, 3, $minor],
            ['Power fault – 3 to 6 eNodeBs', 'mobility_power', 'enodebs', 3, 6, $major],
            ['Power fault – more than 6 eNodeBs', 'mobility_power', 'enodebs', 7, null, $critical],
            ['Power fault – 1 to 2 SSGs', 'mobility_power', 'ssg', 1, 2, $major],
            ['Power fault – 2 or more SSGs', 'mobility_power', 'ssg', 2, null, $critical],
            ['Power fault – 1 or more ASGs', 'mobility_power', 'asg', 1, null, $critical],
            ['Power fault – 1 or more RSGs', 'mobility_power', 'rsg', 1, null, $critical],
        ] as [$name, $typeCode, $metric, $min, $max, $priorityCode]) {
            if (! $vertical || ! isset($type[$typeCode], $priority[$priorityCode])) {
                continue;
            }

            ImpactRule::query()->firstOrCreate(['name' => $name], [
                'vertical_id' => $vertical,
                'outage_type_id' => $type[$typeCode],
                'metric' => $metric,
                'min_value' => $min,
                'max_value' => $max,
                'priority_id' => $priority[$priorityCode],
                'is_active' => true,
            ]);
        }
    }
}
