<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the lists and checks that run all day, for when the tables are large.
 *
 * PostgreSQL (production) does not index a foreign-key column on its own, as MySQL does: "my
 * tickets", "this technician's tickets" and "this team's tickets" were reading the whole outages
 * table there. The others serve what every signed-in page asks for every few seconds (the bell),
 * the desk SLA check and the audit trail's filters.
 */
return new class extends Migration
{
    /** table => list of column sets */
    private const INDEXES = [
        'outages' => [
            ['current_assignee_id', 'is_closed'],
            ['technician_id', 'is_closed'],
            ['team_id', 'is_closed'],
            ['current_queue', 'is_closed'],
            ['workflow', 'is_closed', 'created_at'],
            ['created_by', 'created_at'],
        ],
        'tickets' => [
            ['parent_outage_id'],
            ['linked_outage_id'],
        ],
        'outage_queue_entries' => [
            ['status', 'sla_due_at'],
        ],
        'outage_assignments' => [
            ['queue_entry_id'],
        ],
        'notifications' => [
            ['notifiable_type', 'notifiable_id', 'read_at', 'created_at'],
        ],
        'notification_logs' => [
            ['recipient_user_id', 'created_at'],
        ],
        'audit_logs' => [
            ['event', 'created_at'],
            ['user_id', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $columns) {
                // Left alone where the columns are not there, or an index on exactly these already is.
                if (! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $columns)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $this->name($table, $columns)));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $columns) {
                if (Schema::hasIndex($table, $this->name($table, $columns))) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($this->name($table, $columns)));
                }
            }
        }
    }

    /** Short enough for both databases' limit on names. */
    private function name(string $table, array $columns): string
    {
        return substr($table, 0, 20).'_'.substr(md5(implode(',', $columns)), 0, 8).'_load_idx';
    }
};
