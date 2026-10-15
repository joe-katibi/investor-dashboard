<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outages brought in from another OMS server (oms:import-outages): what they were there, so a second
 * import of the same export never brings them in twice — even when they had to be renumbered here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outage_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->string('source_server', 120)->nullable();
            $table->unsignedBigInteger('source_id');
            $table->string('source_ticket_number', 40);
            $table->dateTime('source_created_at');
            $table->timestamps();
            $table->index(['source_ticket_number', 'source_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outage_imports');
    }
};
