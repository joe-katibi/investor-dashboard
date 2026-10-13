<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where and when a photo was taken. The geolocation photo of a site visit is taken in the mobile
 * app at the phone's position: latitude, longitude, how exact the fix was, the place it resolves
 * to and the moment are kept with the photo — and are also written into the photo file itself,
 * from which they are read back for a photo that arrives without them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outage_attachments', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('note');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('accuracy_m')->nullable()->after('longitude');
            $table->string('place', 255)->nullable()->after('accuracy_m');
            $table->dateTime('taken_at')->nullable()->after('place');
            $table->string('location_source', 10)->nullable()->after('taken_at'); // device | photo
        });
    }

    public function down(): void
    {
        Schema::table('outage_attachments', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'accuracy_m', 'place', 'taken_at', 'location_source']);
        });
    }
};
