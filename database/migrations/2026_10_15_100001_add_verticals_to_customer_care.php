<?php

use App\Models\Vertical;
use Database\Seeders\FourGTicketingSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer tickets by vertical (FTTH, 4G, …): ticket categories, customers, final reasons and
 * reasons for delay each belong to a vertical — everything that exists today is FTTH — and 4G
 * tickets follow their own desks (4G NOC, IP / RF / Core teams) with their vendors.
 */
return new class extends Migration
{
    private const TABLES = ['ticket_categories', 'customers', 'final_reasons', 'delay_reasons'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('vertical_id')->nullable()->constrained()->nullOnDelete();
                $table->index(['vertical_id', 'is_active']);
            });
        }

        // A vendor working a ticket, and whether its people keep everyone posted.
        Schema::table('tickets', function (Blueprint $table) {
            $table->dateTime('vendor_notified_at')->nullable();
            $table->dateTime('vendor_feedback_at')->nullable();
            $table->dateTime('vendor_reminded_at')->nullable();
            $table->dateTime('progress_reminded_at')->nullable();
        });

        // What exists today is FTTH.
        if ($ftth = DB::table('verticals')->where('code', Vertical::FTTH)->value('id')) {
            foreach (self::TABLES as $name) {
                DB::table($name)->whereNull('vertical_id')->update(['vertical_id' => $ftth]);
            }
        }

        // An installation in use gets the 4G catalogue, desks, roles and vendor e-mails now; a fresh one from the seeders.
        if (DB::table('outage_statuses')->exists()) {
            (new FourGTicketingSeeder)->run();
        }
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn(['vendor_notified_at', 'vendor_feedback_at', 'vendor_reminded_at', 'progress_reminded_at']));

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['vertical_id', 'is_active']);
                $table->dropConstrainedForeignId('vertical_id');
            });
        }
    }
};
