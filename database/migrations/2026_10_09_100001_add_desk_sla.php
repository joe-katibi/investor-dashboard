<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA per desk: every stay of a ticket at a desk (a FIFO queue entry) carries the target that
 * applied when it arrived, so changing the targets later never rewrites what already happened.
 * Outages worked by a field team also carry the time by which they should be on the field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outage_queue_entries', function (Blueprint $table) {
            $table->unsignedInteger('sla_minutes')->nullable()->after('escalated_at');
            $table->dateTime('sla_warn_at')->nullable()->after('sla_minutes');
            $table->dateTime('sla_due_at')->nullable()->after('sla_warn_at');
            $table->dateTime('sla_warned_at')->nullable()->after('sla_due_at');
            $table->dateTime('sla_breached_at')->nullable()->after('sla_warned_at');
            $table->index(['status', 'sla_due_at'], 'queue_entries_desk_sla_index');
        });

        Schema::table('outages', function (Blueprint $table) {
            $table->dateTime('field_warn_at')->nullable();
            $table->dateTime('field_due_at')->nullable()->index();
            $table->dateTime('field_warned_at')->nullable();
            $table->dateTime('field_breached_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outage_queue_entries', function (Blueprint $table) {
            $table->dropIndex('queue_entries_desk_sla_index');
            $table->dropColumn(['sla_minutes', 'sla_warn_at', 'sla_due_at', 'sla_warned_at', 'sla_breached_at']);
        });

        Schema::table('outages', function (Blueprint $table) {
            $table->dropIndex(['field_due_at']);
            $table->dropColumn(['field_warn_at', 'field_due_at', 'field_warned_at', 'field_breached_at']);
        });
    }
};
