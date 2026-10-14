<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shifting / relocation visits:
 *   - before the speed tests and the smart-device photos the technician sends the visit to the FTTH
 *     NOC for provisioning; the NOC sends it back to the same technician when it is done;
 *   - a relocation to another OLT / region: the new OLT, address, road and landmark, and a dispatcher
 *     of the new area.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_types', function (Blueprint $table) {
            $table->boolean('is_relocation')->default(false)->after('technician_permission');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('road', 250)->nullable()->after('landmark');
            $table->dateTime('provision_requested_at')->nullable();
            $table->foreignId('provision_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('provisioned_at')->nullable();
            $table->foreignId('provisioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('relocated_at')->nullable();
        });

        DB::table('visit_types')->where('code_prefix', 'SHI')->update(['is_relocation' => true]);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provision_requested_by');
            $table->dropConstrainedForeignId('provisioned_by');
            $table->dropColumn(['road', 'provision_requested_at', 'provisioned_at', 'relocated_at']);
        });

        Schema::table('visit_types', fn (Blueprint $table) => $table->dropColumn('is_relocation'));
    }
};
