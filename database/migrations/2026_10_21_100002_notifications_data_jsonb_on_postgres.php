<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL: the bell's unread count (Filament database notifications) reads data->>'format', which needs JSON,
 * not text. Converted only where the column is still text; MySQL / SQLite are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || $this->type() !== 'text') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && $this->type() === 'jsonb') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        }
    }

    private function type(): ?string
    {
        return DB::table('information_schema.columns')->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', 'notifications')->where('column_name', 'data')->value('data_type');
    }
};
