<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Site visits: completed within 24 business hours (every visit type). Outages stay at 22 hours. Re-score
 * imported history afterwards with `php artisan oms:import-history --rescore`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('visit_types')->whereIn('code_prefix', ['INS', 'WiE', 'SUP', 'SHI'])->update(['default_sla_minutes' => 1440, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('visit_types')->whereIn('code_prefix', ['INS', 'WiE', 'SUP', 'SHI'])->update(['default_sla_minutes' => 1260, 'updated_at' => now()]);
    }
};
