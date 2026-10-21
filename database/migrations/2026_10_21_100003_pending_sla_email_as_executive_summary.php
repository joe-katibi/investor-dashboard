<?php

use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Tickets – Pending / SLA Update" laid out as the executive live page: FTTH and 4G outages, customer tickets and
 * site visits apart, 4G tickets. Schedule, recipients, sender and attachment stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('email_templates')->where('code', 'ticket_pending_update')->update([
            'body' => EmailTemplateSeeder::TICKET_PENDING_BODY,
            'description' => 'Every 3 hours, 07:00–19:00: the executive live summary — FTTH and 4G outages, customer tickets and site visits against the SLA (per department, OLT and region, issues per OLT, closed), 4G tickets. Pending list attached.',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('code', 'ticket_pending_update')->update([
            'body' => EmailTemplateSeeder::TICKET_PENDING_BODY_V1,
            'description' => 'Every 3 hours, 07:00–19:00: pending tickets by SLA per department, OLT and region, issues per OLT, tickets in customer follow-up and what was closed. Pending list attached.',
            'updated_at' => now(),
        ]);
    }
};
