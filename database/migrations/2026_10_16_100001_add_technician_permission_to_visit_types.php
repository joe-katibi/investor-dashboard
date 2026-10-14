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
 * A visit type can be kept for some technicians: Shifting / Relocation visits only go to the
 * technicians given "Technician: Shifting / Relocation visits" (visit.work_shifting), when
 * dispatched by name and through the technician FIFO queue alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_types', function (Blueprint $table) {
            $table->string('technician_permission', 100)->nullable()->after('photo_set');
        });

        DB::table('visit_types')->where('code_prefix', 'SHI')->update(['technician_permission' => VisitType::SHIFTING_PERMISSION]);

        // On a system that has its permissions already (not a fresh install, which the seeders build).
        if (Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'visit.work')->exists()) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Permission::findOrCreate(VisitType::SHIFTING_PERMISSION, 'web');

            if (! Role::query()->where('name', 'Shifting / Relocation Technician')->where('guard_name', 'web')->exists()) {
                $existing = Permission::query()->pluck('name')->all();
                Role::findOrCreate('Shifting / Relocation Technician', 'web')->givePermissionTo(array_values(array_intersect(
                    ['ticket.view', 'visit.work', VisitType::SHIFTING_PERMISSION, 'queue.work_visit_technician'], $existing,
                )));
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::table('visit_types', fn (Blueprint $table) => $table->dropColumn('technician_permission'));
    }
};
