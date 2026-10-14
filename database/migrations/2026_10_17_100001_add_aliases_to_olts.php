<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Other names an OLT is known by (a customer file's "Seguku-OLT" for SEGUKU, "Salama-OLT" for
 * Salaama): the customer and topology imports match them, so they never create the OLT again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->jsonb('aliases')->nullable()->after('code'); // jsonb: PostgreSQL can compare it (OLT lists use DISTINCT)
        });
    }

    public function down(): void
    {
        Schema::table('olts', fn (Blueprint $table) => $table->dropColumn('aliases'));
    }
};
