<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A new ONU on site — Faulty ONU, ONU Upgrade, ONU Delivery, ONU Swap, or an ONU replaced on any
 * visit: the technician sends the visit to the FTTH NOC for provisioning with a photo of the ONU's
 * MAC address, and takes the speed tests and the smart-device photo once it is provisioned.
 */
return new class extends Migration
{
    public const ONU_ISSUES = ['faulty onu', 'onu upgrade', 'onu delivery', 'onu swap'];

    public function up(): void
    {
        Schema::table('ticket_subcategories', function (Blueprint $table) {
            $table->boolean('needs_onu_provisioning')->default(false)->after('check_outage');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('onu_status', 10)->nullable()->after('onu_type'); // existing | replaced
        });

        DB::table('ticket_subcategories')->whereIn(DB::raw('LOWER(name)'), self::ONU_ISSUES)->update(['needs_onu_provisioning' => true]);
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('onu_status'));
        Schema::table('ticket_subcategories', fn (Blueprint $table) => $table->dropColumn('needs_onu_provisioning'));
    }
};
