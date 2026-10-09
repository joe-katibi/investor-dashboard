<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System outages are routed by the affected system: a service can name the team that reviews
 * outages of that system (Savbill and Grafana → CRM Managers, CPaaS → CPaaS Manager); a service
 * without one stays with the IT desk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('review_team_id')->nullable()->after('vertical_id')->constrained('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_team_id');
        });
    }
};
