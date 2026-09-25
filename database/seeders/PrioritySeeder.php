<?php

namespace Database\Seeders;

use App\Models\Priority;
use Illuminate\Database\Seeder;

/** Impact / priority rules by total customers affected, plus FIFO queue rank (spec §6, §27.4). */
class PrioritySeeder extends Seeder
{
    public function run(): void
    {
        // [code, name, severity, FIFO rank, min customers, max customers, colour, default]
        foreach ([
            ['low', 'Low', 1, 4, 0, 9, 'gray', true],
            ['medium', 'Medium', 2, 3, 10, 49, 'info', false],
            ['high', 'High', 3, 2, 50, 199, 'warning', false],
            ['critical', 'Critical', 4, 1, 200, null, 'danger', false],
        ] as [$code, $name, $severity, $rank, $min, $max, $color, $default]) {
            Priority::query()->updateOrCreate(['code' => $code], [
                'name' => $name, 'severity' => $severity, 'queue_rank' => $rank, 'min_customers' => $min,
                'max_customers' => $max, 'color' => $color, 'is_default' => $default, 'is_active' => true,
            ]);
        }
    }
}
