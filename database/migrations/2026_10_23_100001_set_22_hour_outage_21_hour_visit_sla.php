<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The agreed service levels, revised: FTTH outages are restored within 22 hours (every priority, and outages
 * without a priority), site visits are completed within 21 business hours (every visit type). 4G policies and
 * the planned-maintenance window are not changed. Re-score imported history afterwards with
 * `php artisan oms:import-history --rescore`.
 */
return new class extends Migration
{
    private const OUTAGE_MINUTES = 1320;

    private const VISIT_MINUTES = 1260;

    public function up(): void
    {
        foreach (['Critical', 'High', 'Medium', 'Low'] as $level) {
            DB::table('sla_policies')->where('name', "{$level} – 24h")->whereNull('vertical_id')
                ->update(['name' => "{$level} – 22h", 'resolution_minutes' => self::OUTAGE_MINUTES, 'updated_at' => now()]);
        }

        DB::table('visit_types')->whereIn('code_prefix', ['INS', 'WiE', 'SUP', 'SHI'])->update(['default_sla_minutes' => self::VISIT_MINUTES, 'updated_at' => now()]);

        app(Settings::class)->set(['sla.default_resolution_minutes' => self::OUTAGE_MINUTES]);
    }

    public function down(): void
    {
        foreach (['Critical', 'High', 'Medium', 'Low'] as $level) {
            DB::table('sla_policies')->where('name', "{$level} – 22h")->whereNull('vertical_id')
                ->update(['name' => "{$level} – 24h", 'resolution_minutes' => 1440, 'updated_at' => now()]);
        }

        DB::table('visit_types')->whereIn('code_prefix', ['INS', 'WiE', 'SUP', 'SHI'])->update(['default_sla_minutes' => 1440, 'updated_at' => now()]);

        app(Settings::class)->set(['sla.default_resolution_minutes' => 1440]);
    }
};
