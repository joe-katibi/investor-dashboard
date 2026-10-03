<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phones signed in to the mobile app. One person can have several; each row belongs to one
 * sign-in (personal access token), so signing out — or revoking the device — ends that session only.
 * push_provider / push_token: the Firebase Cloud Messaging token push notifications are sent to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('device_uuid', 64);
            $table->string('platform', 20);
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->string('push_provider', 30)->nullable();
            $table->string('push_token', 512)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_devices');
    }
};
