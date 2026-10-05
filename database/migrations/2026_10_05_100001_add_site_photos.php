<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site photos of an outage: Emergency and Regular outages are reported restored with three
 * photos — the findings on the ground, the work after restoration and the power readings.
 * Which outage types need them is a setting of the type; a photo records when it was taken and
 * whether it came from the phone's camera or was uploaded on the website.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outage_types', function (Blueprint $table) {
            $table->boolean('requires_site_photos')->default(false)->after('is_emergency');
        });

        Schema::table('outage_photos', function (Blueprint $table) {
            $table->dateTime('taken_at')->nullable()->after('original_name');
            $table->string('source', 10)->nullable()->after('taken_at'); // camera | upload
        });

        // A running system: the two types the rule was asked for. (A fresh install gets it from the seeder.)
        DB::table('outage_types')->whereIn('code', ['regular', 'emergency'])->update(['requires_site_photos' => true]);
        Cache::forget('oms.outage-types.site-photos.v1');
    }

    public function down(): void
    {
        Schema::table('outage_photos', fn (Blueprint $table) => $table->dropColumn(['taken_at', 'source']));
        Schema::table('outage_types', fn (Blueprint $table) => $table->dropColumn('requires_site_photos'));
    }
};
