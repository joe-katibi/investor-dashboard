<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field maintenance raised by an Infrastructure technician on site:
 *  - preventive: needs Dispatcher + Infrastructure Manager approval of the downtime;
 *  - corrective: work starts at once (e.g. fallen pole, dropped cable).
 * Both carry "before" photos (outside the FAT/FDT with its label, inside) and "after" photos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outage_types', function (Blueprint $table) {
            $table->string('maintenance_kind', 20)->nullable()->after('is_emergency'); // preventive | corrective
        });

        Schema::table('outages', function (Blueprint $table) {
            $table->string('maintenance_kind', 20)->nullable()->after('outage_type_id')->index();
            $table->string('site_label', 120)->nullable()->after('location');
            $table->dateTime('downtime_start')->nullable()->after('end_time');
            $table->dateTime('downtime_end')->nullable()->after('downtime_start');
            $table->dateTime('pm_approved_at')->nullable()->after('downtime_end');
        });

        Schema::create('outage_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 10);   // before | after
            $table->string('position', 10); // outside | inside
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['outage_id', 'stage']);
        });

        Schema::create('maintenance_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 20); // dispatcher | manager
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision', 20); // approved | rejected
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['outage_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_approvals');
        Schema::dropIfExists('outage_photos');

        Schema::table('outages', function (Blueprint $table) {
            $table->dropIndex(['maintenance_kind']);
            $table->dropColumn(['maintenance_kind', 'site_label', 'downtime_start', 'downtime_end', 'pm_approved_at']);
        });

        Schema::table('outage_types', fn (Blueprint $table) => $table->dropColumn('maintenance_kind'));
    }
};
