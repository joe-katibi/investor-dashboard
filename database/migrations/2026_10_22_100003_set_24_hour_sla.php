<?php

use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The agreed service levels: FTTH outages are restored within 24 hours (every priority, and outages without
 * a priority), site visits are completed within 24 business hours (every visit type, shifting included).
 * 4G policies and the planned-maintenance window are not changed.
 */
return new class extends Migration
{
    private const POLICIES = [
        // old name => [new name, resolution minutes]
        'Critical – 4h' => ['Critical – 24h', 1440],
        'High – 8h' => ['High – 24h', 1440],
        'Medium – 12h' => ['Medium – 24h', 1440],
        'Low – 24h' => ['Low – 24h', 1440],
    ];

    public function up(): void
    {
        foreach (self::POLICIES as $old => [$name, $minutes]) {
            DB::table('sla_policies')->where('name', $old)->update(['name' => $name, 'resolution_minutes' => $minutes, 'updated_at' => now()]);
        }

        DB::table('visit_types')->whereIn('code_prefix', ['INS', 'WiE', 'SUP', 'SHI'])->update(['default_sla_minutes' => 1440, 'updated_at' => now()]);

        app(Settings::class)->set(['sla.default_resolution_minutes' => 1440]);
    }

    public function down(): void
    {
        $previous = ['Critical – 24h' => ['Critical – 4h', 240], 'High – 24h' => ['High – 8h', 480], 'Medium – 24h' => ['Medium – 12h', 720], 'Low – 24h' => ['Low – 24h', 1440]];
        foreach ($previous as $current => [$name, $minutes]) {
            DB::table('sla_policies')->where('name', $current)->whereNull('vertical_id')->update(['name' => $name, 'resolution_minutes' => $minutes, 'updated_at' => now()]);
        }

        foreach (['INS' => null, 'WiE' => null, 'SUP' => 180, 'SHI' => 4320] as $prefix => $minutes) {
            DB::table('visit_types')->where('code_prefix', $prefix)->update(['default_sla_minutes' => $minutes, 'updated_at' => now()]);
        }

        app(Settings::class)->set(['sla.default_resolution_minutes' => 240]);
    }
};
