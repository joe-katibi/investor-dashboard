<?php

namespace Database\Seeders;

use App\Models\NotificationRule;
use App\Models\Priority;
use Illuminate\Database\Seeder;

/** Default notification rules (spec §12). Requires PrioritySeeder and RolesAndPermissionsSeeder. */
class NotificationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $critical = Priority::query()->where('code', Priority::CRITICAL)->value('id');

        // [name, event, priority filter, channels, recipient roles, notify assignee, notify team leaders]
        foreach ([
            ['NOC escalation alert', 'noc_escalation', null, ['database'], ['NOC Team Leader', 'NOC Tier II', 'NOC Tier I'], false, false],
            ['Infrastructure escalation alert', 'infrastructure_escalation', null, ['database'], ['Infrastructure Team Leader', 'Infrastructure Dispatcher'], false, false],
            ['Critical outage created', 'outage_created', $critical, ['database', 'mail'], ['NOC Manager', 'Infrastructure Manager', 'Executive'], false, false],
            ['Assignment rejected – leaders', 'assignment_rejected', null, ['database'], [], false, true],
            ['Queue escalation – managers', 'queue_escalation', null, ['database', 'mail'], ['NOC Manager', 'Infrastructure Manager'], false, true],
            ['Restoration awaiting NOC', 'restore_reported', null, ['database'], ['NOC Team Leader'], false, false],
            ['Outage resolved – CX follow-up', 'resolved', null, ['database'], ['CX Team Leader'], false, false],
            ['Credit due – CX', 'credit_due', null, ['database', 'mail'], ['CX Manager', 'CX Team Leader'], false, false],
        ] as [$name, $event, $priorityId, $channels, $roles, $assignee, $leaders]) {
            NotificationRule::query()->updateOrCreate(['name' => $name], [
                'event' => $event,
                'priority_id' => $priorityId,
                'channels' => $channels,
                'recipient_roles' => $roles,
                'notify_assignee' => $assignee,
                'notify_team_leaders' => $leaders,
                'is_active' => true,
            ]);
        }
    }
}
