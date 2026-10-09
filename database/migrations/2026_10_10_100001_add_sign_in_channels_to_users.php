<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who signed in where: the website and the mobile app each get their own "last signed in" time
 * (last_login_at stays: the latest of the two).
 *
 * Until now only one time was kept, so the past is filled in as well as it can be known: someone
 * holding (or having held) an app sign-in gets its time as their app sign-in; the time already on
 * file counts as a website sign-in, unless it is that same app sign-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('last_web_login_at')->nullable()->after('last_login_at');
            $table->dateTime('last_mobile_login_at')->nullable()->after('last_web_login_at');
        });

        $mobile = [];

        if (Schema::hasTable('personal_access_tokens')) {
            foreach (DB::table('personal_access_tokens')->where('abilities', 'like', '%mobile%')->get(['tokenable_id', 'created_at']) as $token) {
                $mobile[$token->tokenable_id] = max($mobile[$token->tokenable_id] ?? '', (string) $token->created_at);
            }
        }

        if (Schema::hasTable('mobile_devices')) {
            foreach (DB::table('mobile_devices')->get(['user_id', 'created_at']) as $device) {
                $mobile[$device->user_id] = max($mobile[$device->user_id] ?? '', (string) $device->created_at);
            }
        }

        foreach (DB::table('users')->whereNotNull('last_login_at')->orWhereIn('id', array_keys($mobile) ?: [0])->get(['id', 'last_login_at']) as $user) {
            $app = $mobile[$user->id] ?? null;
            // The app's sign-in also stamped last_login_at: within a minute of each other they are the same event.
            $sameEvent = $app && $user->last_login_at && abs(strtotime((string) $user->last_login_at) - strtotime($app)) <= 60;

            DB::table('users')->where('id', $user->id)->update([
                'last_mobile_login_at' => $app ?: null,
                'last_web_login_at' => $sameEvent ? null : $user->last_login_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_web_login_at', 'last_mobile_login_at']);
        });
    }
};
