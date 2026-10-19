<?php

use App\Enums\QueueEntryStatus;
use App\Enums\QueueType;
use App\Models\DeskRoute;
use App\Models\QueueDefinition;
use App\Support\Settings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shifting / Relocation visits get a dispatch desk of their own, "Shifting Dispatch" (queue.work_shifting_dispatch):
 * dispatchers who only handle shifting no longer receive Support visits of their region, and Site Visit Dispatch no
 * longer receives shifting. The desk takes the settings of Site Visit Dispatch (queue, routes, SLA target); open
 * shifting visits waiting at Site Visit Dispatch move to it (whoever holds one keeps it). "Inhouse Shifting" becomes a
 * Technical Support case: raised as a Support visit, at Site Visit Dispatch.
 */
return new class extends Migration
{
    private const SHIFTING_ROLES = ['Shifting / Relocation Dispatcher', 'Shifting Dispatcher'];

    public function up(): void
    {
        $visit = QueueType::VisitDispatch->value;
        $shifting = QueueType::ShiftingDispatch->value;
        $now = now();

        // The queue, with the settings of Site Visit Dispatch.
        $source = QueueDefinition::query()->where('queue_type', $visit)->orderBy('id')->first();
        $definition = QueueDefinition::query()->where('queue_type', $shifting)->first() ?? QueueDefinition::query()->create(array_merge(
            $source ? $source->only(['department_id', 'team_id', 'vertical_id', 'assignment_mode', 'ack_timeout_minutes', 'escalate_after_attempts', 'sort_order']) : ['assignment_mode' => 'auto', 'ack_timeout_minutes' => 30, 'escalate_after_attempts' => 3, 'sort_order' => 20],
            ['name' => QueueType::ShiftingDispatch->getLabel().' Queue', 'queue_type' => $shifting, 'required_permission' => QueueType::ShiftingDispatch->defaultPermission(), 'is_active' => true],
        ));

        // The desks it may hand a visit to: those of Site Visit Dispatch.
        foreach (DeskRoute::query()->where('from_desk', $visit)->get() as $route) {
            DeskRoute::query()->firstOrCreate(['from_desk' => $shifting, 'to_desk' => $route->to_desk->value], $route->only(['label', 'sort_order', 'is_active']));
        }

        // Its SLA target: that of Site Visit Dispatch, when one is set.
        $minutes = json_decode((string) DB::table('settings')->where('key', 'desk_sla.minutes')->value('value'), true);
        if (is_array($minutes) && isset($minutes[$visit]) && ! isset($minutes[$shifting])) {
            DB::table('settings')->where('key', 'desk_sla.minutes')->update(['value' => json_encode($minutes + [$shifting => $minutes[$visit]])]);
            app(Settings::class)->flush();
        }

        // Open shifting visits at Site Visit Dispatch move to their desk.
        $shiftingVisits = DB::table('tickets')->join('visit_types', 'visit_types.id', '=', 'tickets.visit_type_id')
            ->whereNotNull('visit_types.dispatcher_permission')->pluck('tickets.outage_id');
        $open = DB::table('outages')->whereIn('id', $shiftingVisits)->where('is_closed', false)->pluck('id');
        foreach ($open->chunk(500) as $ids) {
            DB::table('outages')->whereIn('id', $ids)->where('current_queue', $visit)->update(['current_queue' => $shifting]);
            DB::table('tickets')->whereIn('outage_id', $ids)->where('desk', $visit)->update(['desk' => $shifting]);
            DB::table('outage_queue_entries')->whereIn('outage_id', $ids)->where('queue_type', $visit)->whereIn('status', QueueEntryStatus::openValues())
                ->update(['queue_type' => $shifting, 'queue_definition_id' => $definition->id, 'updated_at' => $now]);
            DB::table('outage_assignments')->whereIn('outage_id', $ids)->where('queue_type', $visit)->whereIn('status', ['assigned', 'accepted'])->update(['queue_type' => $shifting]);
        }
        DB::table('tickets')->whereIn('outage_id', $shiftingVisits)->where('home_desk', $visit)->update(['home_desk' => $shifting]);

        // "Inhouse Shifting": a Technical Support case, raised as a Support visit.
        $support = DB::table('visit_types')->where('code_prefix', 'SUP')->value('id');
        $technical = DB::table('ticket_categories')->where('code', 'TECH')->value('id');
        if ($support && $technical) {
            DB::table('ticket_subcategories')->where('code', 'SHIFT-INHOUSE-SHIFTING')
                ->update(['ticket_category_id' => $technical, 'visit_type_id' => $support, 'code' => 'TECH-INHOUSE-SHIFTING', 'updated_at' => $now]);
        }

        // The desk permission: the Shifting dispatcher roles work Shifting Dispatch instead of Site Visit Dispatch.
        if (Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'visit.dispatch')->exists()) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Permission::findOrCreate(QueueType::ShiftingDispatch->defaultPermission(), 'web');

            foreach (Role::query()->whereIn('name', self::SHIFTING_ROLES)->where('guard_name', 'web')->get() as $role) {
                $role->givePermissionTo(QueueType::ShiftingDispatch->defaultPermission());
                if ($role->hasPermissionTo(QueueType::VisitDispatch->defaultPermission())) {
                    $role->revokePermissionTo(QueueType::VisitDispatch->defaultPermission());
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Back at Site Visit Dispatch; the desk's queue and routes are left (inactive desks do nothing).
        foreach (['outages' => 'current_queue', 'tickets' => 'desk'] as $table => $column) {
            DB::table($table)->where($column, QueueType::ShiftingDispatch->value)->update([$column => QueueType::VisitDispatch->value]);
        }
        DB::table('tickets')->where('home_desk', QueueType::ShiftingDispatch->value)->update(['home_desk' => QueueType::VisitDispatch->value]);
        DB::table('outage_queue_entries')->where('queue_type', QueueType::ShiftingDispatch->value)->whereIn('status', QueueEntryStatus::openValues())->update(['queue_type' => QueueType::VisitDispatch->value]);
        DB::table('outage_assignments')->where('queue_type', QueueType::ShiftingDispatch->value)->whereIn('status', ['assigned', 'accepted'])->update(['queue_type' => QueueType::VisitDispatch->value]);
    }
};
