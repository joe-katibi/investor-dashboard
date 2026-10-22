<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * outages.data_source — incident_log / ticket_tracker (history imported from the spreadsheets) or demo
 * (generated to fill months without real data); null = raised in the system.
 * users.engagement_type — inhouse / outsourced, from the technician list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outages', function (Blueprint $table): void {
            $table->string('data_source', 20)->nullable()->index();
            // Time to restore (outages, clock hours) or to complete (site visits, business hours), as measured by the source.
            $table->decimal('elapsed_hours', 10, 2)->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('engagement_type', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outages', function (Blueprint $table): void {
            $table->dropIndex(['data_source']);
            $table->dropColumn(['data_source', 'elapsed_hours']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('engagement_type');
        });
    }
};
