<?php

namespace Database\Seeders;

use App\Models\Priority;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

/**
 * Impact / priority levels per vertical, plus FIFO queue rank (spec §6, §27.4).
 *  - FTTH: by total customers affected (the ranges below).
 *  - 4G:   Minor / Major / Critical by network elements affected (see ImpactRuleSeeder).
 */
class PrioritySeeder extends Seeder
{
    public function run(): void
    {
        $ftth = Vertical::query()->where('code', Vertical::FTTH)->value('id');
        $mobility = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');

        // [code, name, severity, FIFO rank, min customers, max customers, colour, default]
        foreach ([
            ['low', 'Low', 1, 4, 0, 9, 'gray', true],
            ['medium', 'Medium', 2, 3, 10, 49, 'info', false],
            ['high', 'High', 3, 2, 50, 199, 'warning', false],
            ['critical', 'Critical', 4, 1, 200, null, 'danger', false],
        ] as [$code, $name, $severity, $rank, $min, $max, $color, $default]) {
            Priority::query()->updateOrCreate(['code' => $code], [
                'name' => $name, 'vertical_id' => $ftth, 'severity' => $severity, 'queue_rank' => $rank, 'min_customers' => $min,
                'max_customers' => $max, 'color' => $color, 'is_default' => $default, 'is_active' => true,
            ]);
        }

        // [code, name, severity, FIFO rank, colour, default]
        foreach ([
            [Priority::MOBILITY_MINOR, '4G Minor', 1, 4, 'gray', true],
            [Priority::MOBILITY_MAJOR, '4G Major', 3, 2, 'warning', false],
            [Priority::MOBILITY_CRITICAL, '4G Critical', 4, 1, 'danger', false],
        ] as [$code, $name, $severity, $rank, $color, $default]) {
            // firstOrCreate: edited under 4G NOC → 4G Severity Rules and must survive re-seeding.
            Priority::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'vertical_id' => $mobility, 'severity' => $severity, 'queue_rank' => $rank,
                'color' => $color, 'is_default' => $default, 'is_active' => true,
                'description' => '4G severity — set by the impact rules (eNodeBs / SSGs / ASGs / RSGs affected).',
            ]);
        }
    }
}
