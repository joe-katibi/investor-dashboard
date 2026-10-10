<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signing a CRF with a signature style (the name written in a chosen handwriting) as well as by
 * drawing: each signing records how it was signed, and a person's preferred style is kept for the
 * next time — by account, or by e-mail address for a signatory without one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_request_signatures', function (Blueprint $table) {
            $table->string('signature_method', 10)->nullable()->after('signature_image'); // drawn | typed
            $table->string('signature_style', 40)->nullable()->after('signature_method');
        });

        Schema::create('user_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable()->unique();
            $table->string('style_key', 40);
            $table->string('display_name_snapshot', 150);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_signatures');

        Schema::table('change_request_signatures', function (Blueprint $table) {
            $table->dropColumn(['signature_method', 'signature_style']);
        });
    }
};
