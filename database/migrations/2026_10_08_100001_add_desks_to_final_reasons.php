<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final reasons by desk: a billing agent closing a ticket is offered the billing reasons, the NOC
 * the NOC ones, a technician the site-visit ones. "desks" lists the desks (queue values) a reason
 * is offered at; empty = every desk, which is how every existing reason starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('final_reasons', function (Blueprint $table) {
            $table->json('desks')->nullable()->after('applies_to');
        });
    }

    public function down(): void
    {
        Schema::table('final_reasons', fn (Blueprint $table) => $table->dropColumn('desks'));
    }
};
