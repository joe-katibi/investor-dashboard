<?php

namespace Database\Seeders;

use App\Models\MajorOutageType;
use App\Models\OutageType;
use Illuminate\Database\Seeder;

/** Outage types and ticket prefixes (spec §5). Requires MajorOutageTypeSeeder. */
class OutageTypeSeeder extends Seeder
{
    public function run(): void
    {
        $major = MajorOutageType::query()->pluck('id', 'code');

        // [code, name, major type, prefix, planned, emergency, sort, field maintenance kind]
        foreach ([
            ['regular', 'Regular Outage', MajorOutageType::NETWORK, 'OUT', false, false, 1, null],
            ['emergency', 'Emergency Outage', MajorOutageType::NETWORK, 'EMO', false, true, 2, null],
            ['planned', 'Planned Maintenance', MajorOutageType::NETWORK, 'PLM', true, false, 3, null],
            ['core', 'SEACOM / Core Service Outage', MajorOutageType::CORE, 'CORE', false, false, 4, null],
            ['system', 'System Outage', MajorOutageType::SYSTEM, 'SYS', false, false, 5, null],
            // Raised on site by an Infrastructure technician (with before / after photos).
            ['preventive', 'Preventive Maintenance', MajorOutageType::NETWORK, 'PVM', false, false, 6, OutageType::PREVENTIVE],
            ['corrective', 'Corrective Maintenance', MajorOutageType::NETWORK, 'CRM', false, false, 7, OutageType::CORRECTIVE],
        ] as [$code, $name, $majorCode, $prefix, $planned, $emergency, $sort, $kind]) {
            OutageType::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'major_outage_type_id' => $major[$majorCode],
                'prefix' => $prefix,
                'is_planned' => $planned,
                'is_emergency' => $emergency,
                'maintenance_kind' => $kind,
                'sort_order' => $sort,
                'is_active' => true,
            ]);
        }
    }
}
