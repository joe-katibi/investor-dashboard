<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL cannot compare "json" values, and the OLT pick-lists select OLTs with DISTINCT: every page
 * with one failed once olts.aliases existed. "jsonb" can be compared. (MySQL / SQLite: nothing to do.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE olts ALTER COLUMN aliases TYPE jsonb USING aliases::jsonb');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE olts ALTER COLUMN aliases TYPE json USING aliases::json');
        }
    }
};
