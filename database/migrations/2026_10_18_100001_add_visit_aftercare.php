<?php

use App\Support\Settings;
use Database\Seeders\QueueDefinitionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The 72-hour follow-up call after a site visit: 72 hours after CX Field Support confirms a visit it
 * goes to the "CX Follow-up – Site Visit" queue; the agent calls the customer and records the
 * disposition, feedback and what happens next.
 */
return new class extends Migration
{
    public const ROLE = 'CX Follow-up – Site Visit Agent';

    public const PERMISSION = 'queue.work_visit_aftercare';

    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dateTime('aftercare_due_at')->nullable();
            $table->dateTime('aftercare_done_at')->nullable();
            $table->string('aftercare_result', 30)->nullable(); // satisfied | dissatisfied | vandalism | … | reopened | new_ticket | unreachable
            $table->unsignedSmallInteger('aftercare_attempts')->default(0);
            $table->index(['aftercare_due_at', 'aftercare_done_at']);
        });

        Schema::table('ticket_follow_ups', function (Blueprint $table) {
            $table->string('call_outcome', 20)->nullable();
            $table->string('feedback', 20)->nullable();
            $table->string('disposition', 40)->nullable();
            $table->string('action_required', 30)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('service_working')->nullable();
            $table->string('installation_quality', 20)->nullable();
            $table->string('technician_conduct', 20)->nullable();
            $table->dateTime('callback_at')->nullable();
            $table->foreignId('new_outage_id')->nullable()->constrained('outages')->nullOnDelete();
            $table->index(['kind', 'called_at']);
        });

        // On a system with tickets already, only visits confirmed from now on are called: the visits
        // closed before are not queued all at once.
        if (DB::table('outages')->exists()) {
            app(Settings::class)->set(['ticketing.aftercare_since' => now()->toDateTimeString()]);
        }

        // On a system that has its permissions already (a fresh install gets them from the seeders).
        if (Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'ticket.view')->exists()) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Permission::findOrCreate(self::PERMISSION, 'web');

            if (! Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->exists()) {
                $existing = Permission::query()->pluck('name')->all();
                Role::findOrCreate(self::ROLE, 'web')->givePermissionTo(array_values(array_intersect(
                    ['ticket.view', 'visit.view', 'ticket.create', 'queue.view', self::PERMISSION], $existing,
                )));
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
            app(QueueDefinitionSeeder::class)->ticketing();
        }
    }

    public function down(): void
    {
        Schema::table('ticket_follow_ups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('new_outage_id');
            $table->dropIndex(['kind', 'called_at']);
            $table->dropColumn(['call_outcome', 'feedback', 'disposition', 'action_required', 'rating', 'service_working', 'installation_quality', 'technician_conduct', 'callback_at']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['aftercare_due_at', 'aftercare_done_at']);
            $table->dropColumn(['aftercare_due_at', 'aftercare_done_at', 'aftercare_result', 'aftercare_attempts']);
        });
    }
};
