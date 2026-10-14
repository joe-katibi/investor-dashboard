<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The scheduled 4G ticket e-mails — every 2 hours, daily at 07:00 and 19:00, weekly on Monday 07:00,
 * monthly on the 1st at 06:00 — on a system that has its e-mail templates already (a fresh install
 * gets them from the seeders). Created inactive: set the recipients, then switch them on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('email_templates')->exists()) {
            app(EmailTemplateSeeder::class)->fourGTickets();
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->whereIn('code', ['ticket_4g_update', 'ticket_4g_daily', 'ticket_4g_weekly', 'ticket_4g_monthly'])->delete();
    }
};
