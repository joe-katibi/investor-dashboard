<?php

use App\Models\VisitType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two permissions of Customer Tickets & Site Visits:
 *  - "Assign / reassign and override FIFO on customer tickets and site visits" (ticket.override). Until
 *    now "queue.override" covered them; everyone who had it keeps the same rights through the new one,
 *    and the two can be given separately from here on.
 *  - "Dispatch Shifting / Relocation visits" (visit.dispatch_shifting): a visit type can be kept for some
 *    dispatchers, like for some technicians. Shifting is, by default; a role "Shifting / Relocation
 *    Dispatcher" is added and the Ticketing Manager role gets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_types', function (Blueprint $table) {
            $table->string('dispatcher_permission', 100)->nullable()->after('technician_permission');
        });

        DB::table('visit_types')->where('code_prefix', 'SHI')->update(['dispatcher_permission' => VisitType::SHIFTING_DISPATCH_PERMISSION]);

        // On a system that has its permissions already (not a fresh install, which the seeders build).
        if (! Schema::hasTable('permissions') || ! DB::table('permissions')->where('name', 'visit.dispatch')->exists()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $override = Permission::findOrCreate('ticket.override', 'web');
        $shifting = Permission::findOrCreate(VisitType::SHIFTING_DISPATCH_PERMISSION, 'web');

        // Whoever could override FIFO on tickets keeps it: roles and people given it directly.
        $old = Permission::query()->where('name', 'queue.override')->where('guard_name', 'web')->first();

        if ($old) {
            Role::query()->whereHas('permissions', fn ($q) => $q->whereKey($old->id))->get()
                ->each(fn (Role $role) => $role->givePermissionTo($override));

            $direct = DB::table('model_has_permissions')->where('permission_id', $old->id)->get(['model_type', 'model_id']);

            foreach ($direct as $row) {
                DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $override->id, 'model_type' => $row->model_type, 'model_id' => $row->model_id]);
            }
        }

        Role::query()->where('name', 'Ticketing Manager')->where('guard_name', 'web')->first()?->givePermissionTo($shifting);

        if (! Role::query()->where('name', 'Shifting / Relocation Dispatcher')->where('guard_name', 'web')->exists()) {
            $existing = Permission::query()->pluck('name')->all();
            Role::findOrCreate('Shifting / Relocation Dispatcher', 'web')->givePermissionTo(array_values(array_intersect([
                'ticket.view', 'ticket.work', 'ticket.cancel', 'visit.create', 'visit.dispatch', VisitType::SHIFTING_DISPATCH_PERMISSION,
                'queue.view', 'queue.work_visit_dispatch', 'reports.view',
            ], $existing)));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('visit_types', fn (Blueprint $table) => $table->dropColumn('dispatcher_permission'));
    }
};
