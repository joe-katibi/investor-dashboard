<?php

namespace Database\Seeders;

use App\Enums\StatusCode;
use App\Models\MajorOutageType;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * What custom workflows need to exist: the ticket type their tickets are built on, three statuses
 * and the permissions. Only adds what is missing; the migration runs it on an installation in use:
 *
 *   php artisan db:seed --class=CustomWorkflowSeeder --force
 */
class CustomWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $major = MajorOutageType::query()->firstOrCreate(['code' => MajorOutageType::CUSTOM], [
            'name' => 'Custom Workflow',
            'description' => 'Tickets of the workflows built under Configuration → Custom Workflows.',
            'requires_noc_confirmation' => false, 'requires_infrastructure' => false, 'uses_topology' => false, 'requires_it_review' => false,
            'sort_order' => 90, 'is_active' => true,
        ]);

        OutageType::query()->firstOrCreate(['code' => OutageType::CODE_CUSTOM], [
            'name' => 'Custom Workflow Ticket',
            'major_outage_type_id' => $major->id,
            'prefix' => 'CWF',
            'workflow' => OutageType::WORKFLOW_CUSTOM,
            'description' => 'A ticket of a custom workflow; its number starts with the prefix of the flow.',
            'sort_order' => 90, 'is_active' => true,
        ]);

        // [code, name, colour, closed, resolved, sort]
        foreach ([
            [StatusCode::CustomOpen, 'Workflow: In Progress', 'primary', false, false, 90],
            [StatusCode::CustomClosed, 'Workflow: Completed', 'success', true, true, 91],
            [StatusCode::CustomCancelled, 'Workflow: Cancelled', 'gray', true, false, 92],
        ] as [$code, $name, $color, $closed, $resolved, $sort]) {
            $status = OutageStatus::query()->firstOrNew(['code' => $code->value]);
            $status->forceFill(['workflow' => OutageType::WORKFLOW_CUSTOM, 'is_closed' => $closed, 'is_resolved' => $resolved, 'is_system' => true, 'is_manual_selectable' => false]);

            if (! $status->exists) {
                $status->forceFill(['name' => $name, 'color' => $color, 'sort_order' => $sort, 'prompt_final_reason' => false, 'pauses_sla' => false, 'is_active' => true]);
            }

            $status->save();
        }

        $this->permissions();
    }

    /**
     * The permissions, the first time they appear: administrators of the workflows may see, raise
     * and cancel; people who may override FIFO (managers, team leaders) may see and cancel. Raising
     * is otherwise granted per role under Roles & Permissions (and narrowed per flow).
     */
    private function permissions(): void
    {
        if (! Schema::hasTable(config('permission.table_names.permissions', 'permissions'))) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $new = [];

        foreach (array_keys(Permissions::GROUPS['Custom Workflows']) as $name) {
            if (! Permission::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
                Permission::findOrCreate($name, 'web');
                $new[] = $name;
            }
        }

        foreach ([
            'workflow.configure' => ['custom.view', 'custom.raise', 'custom.cancel'],
            'queue.override' => ['custom.view', 'custom.cancel'],
        ] as $held => $granted) {
            $granted = array_values(array_intersect($granted, $new));

            if ($granted !== []) {
                Role::query()->where('guard_name', 'web')->whereHas('permissions', fn ($q) => $q->where('name', $held))->get()
                    ->each(fn (Role $role) => $role->givePermissionTo($granted));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
