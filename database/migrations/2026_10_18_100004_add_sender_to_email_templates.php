<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which alias a scheduled e-mail comes from (App\Support\MailSenders: outages, customer tickets, site visits,
 * 4G outages, 4G tickets, executive summary, alerts & updates). Empty: the alias of its report type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('sender', 40)->nullable()->after('period_hours');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', fn (Blueprint $table) => $table->dropColumn('sender'));
    }
};
