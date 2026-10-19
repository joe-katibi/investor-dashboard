<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Database speed-ups found on the production server (October 2026):
 *  - Searching customers ("part of a name, phone or account") read all 140,000 rows every time: on PostgreSQL the
 *    search is lower(column::text) LIKE '%…%', which only a trigram index (pg_trgm) can serve. Those indexes replace
 *    the plain ones on name and phone, which nothing used (no exact lookup by name or phone).
 *  - Pruning old in-app notifications (oms:prune-history) needs their date indexed (notification_logs has it already).
 * On PostgreSQL the indexes are built CONCURRENTLY, so the tables stay usable while they are made.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** Searched columns of the customer list (CustomerResource) => index name. */
    private const SEARCHED = [
        'account_number' => 'customers_account_number_trgm',
        'name' => 'customers_name_trgm',
        'primary_phone' => 'customers_primary_phone_trgm',
    ];

    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        if ($pgsql) {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS notifications_created_at_index ON notifications (created_at)');
        } else {
            Schema::table('notifications', fn (Blueprint $table) => $table->index('created_at', 'notifications_created_at_index'));
        }

        if (! $pgsql || ! $this->trigramAvailable()) {
            return;
        }

        foreach (self::SEARCHED as $column => $index) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON customers USING gin (lower(({$column})::text) gin_trgm_ops)");
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_name_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS customers_primary_phone_index');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::SEARCHED as $index) {
                DB::statement("DROP INDEX IF EXISTS {$index}");
            }
            DB::statement('CREATE INDEX IF NOT EXISTS customers_name_index ON customers (name)');
            DB::statement('CREATE INDEX IF NOT EXISTS customers_primary_phone_index ON customers (primary_phone)');
            DB::statement('DROP INDEX IF EXISTS notifications_created_at_index');

            return;
        }

        Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex('notifications_created_at_index'));
    }

    /**
     * pg_trgm comes with PostgreSQL. Since PostgreSQL 13 the owner of the database may switch it on; otherwise it is
     * switched on once as postgres: sudo -u postgres psql -d <database> -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
     */
    private function trigramAvailable(): bool
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable $exception) {
            Log::warning('Customer search indexes skipped: pg_trgm could not be switched on ('.$exception->getMessage().'). '
                .'Switch it on as postgres (CREATE EXTENSION pg_trgm;), then: php artisan migrate:rollback --step=1 && php artisan migrate');

            return false;
        }

        return true;
    }
};
