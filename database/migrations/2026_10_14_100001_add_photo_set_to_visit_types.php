<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which photos a visit of each kind is completed with (App\Support\VisitPhotos): the geolocation
 * and speed-test photo, or the nine photos of an installation / relocation. Installation and
 * Shifting visits get the nine, and need their evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_types', function (Blueprint $table) {
            $table->string('photo_set', 20)->default('standard')->after('requires_evidence'); // standard | installation
        });

        DB::table('visit_types')->whereIn('code_prefix', ['INS', 'SHI'])->update(['photo_set' => 'installation', 'requires_evidence' => true]);
    }

    public function down(): void
    {
        Schema::table('visit_types', fn (Blueprint $table) => $table->dropColumn('photo_set'));
    }
};
