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

        // [code, name, major type, prefix, planned, emergency, sort]
        foreach ([
            ['regular', 'Regular Outage', MajorOutageType::NETWORK, 'OUT', false, false, 1],
            ['emergency', 'Emergency Outage', MajorOutageType::NETWORK, 'EMO', false, true, 2],
            ['planned', 'Planned Maintenance', MajorOutageType::NETWORK, 'PLM', true, false, 3],
            ['core', 'SEACOM / Core Service Outage', MajorOutageType::CORE, 'CORE', false, false, 4],
            ['system', 'System Outage', MajorOutageType::SYSTEM, 'SYS', false, false, 5],
        ] as [$code, $name, $majorCode, $prefix, $planned, $emergency, $sort]) {
            OutageType::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'major_outage_type_id' => $major[$majorCode],
                'prefix' => $prefix,
                'is_planned' => $planned,
                'is_emergency' => $emergency,
                'sort_order' => $sort,
                'is_active' => true,
            ]);
        }
    }
}
