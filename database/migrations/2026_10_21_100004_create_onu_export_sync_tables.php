<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer database kept up to date from the ONU exports of the network management system (oms:sync-onu-export):
 *  - customers: the ONU's type, physical address (serial) and creation time, and its OLT / slot / port as the export
 *    gives them (an OMS slot and port hang on an FDT, which the export does not know);
 *  - onu_sync_runs: every dump read — which file, what it changed, what it could not place;
 *  - customer_onu_changes: every value a sync changed on a customer, old and new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('onu_type', 60)->nullable()->after('bandwidth');
            $table->string('onu_physical_address', 60)->nullable()->after('onu_type')->index();
            $table->dateTime('onu_created_at')->nullable()->after('onu_physical_address');
            $table->string('onu_olt_name', 120)->nullable()->after('onu_created_at');
            $table->string('onu_slot', 20)->nullable()->after('onu_olt_name');
            $table->string('onu_port', 20)->nullable()->after('onu_slot');
        });

        Schema::create('onu_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 30);
            $table->string('file', 255);
            $table->dateTime('dumped_at')->nullable();
            $table->string('status', 20)->default('running'); // running | done | failed
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('customers_matched')->default(0);
            $table->unsignedInteger('customers_created')->default(0);
            $table->unsignedInteger('customers_updated')->default(0);
            $table->unsignedInteger('customers_unchanged')->default(0);
            $table->unsignedInteger('changes')->default(0);
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->index(['source', 'dumped_at']);
        });

        Schema::create('customer_onu_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onu_sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('account_number', 60)->index();
            $table->string('field', 40);
            $table->string('old_value', 255)->nullable();
            $table->string('new_value', 255)->nullable();
            $table->dateTime('created_at')->index();
            $table->index(['customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_onu_changes');
        Schema::dropIfExists('onu_sync_runs');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['onu_physical_address']);
            $table->dropColumn(['onu_type', 'onu_physical_address', 'onu_created_at', 'onu_olt_name', 'onu_slot', 'onu_port']);
        });
    }
};
