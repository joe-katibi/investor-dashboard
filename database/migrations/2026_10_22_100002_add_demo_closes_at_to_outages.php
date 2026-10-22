<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When an open demo site visit is closed by the demo generator (its sampled completion time). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outages', function (Blueprint $table): void {
            $table->dateTime('demo_closes_at')->nullable()->index();
        });

        // The DEMO-V-n / DEMO-O-n counters.
        Schema::create('demo_sequence', function (Blueprint $table): void {
            $table->string('kind', 5)->primary();
            $table->unsignedBigInteger('last')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_sequence');

        Schema::table('outages', function (Blueprint $table): void {
            $table->dropIndex(['demo_closes_at']);
            $table->dropColumn('demo_closes_at');
        });
    }
};
