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

        $inAppAndMail = ['database', 'mail'];

        // Lifecycle alerts go in-app AND by e-mail. Specific extra addresses are managed on
        // Configuration → Alert E-mails (they receive every selected event).
        // [name, event, priority filter, channels, recipient roles, notify assignee, notify team leaders]
        foreach ([
            ['Ticket created – NOC', 'outage_created', null, $inAppAndMail, ['NOC Manager', 'NOC Team Leader'], false, false],
            ['Critical outage created', 'outage_created', $critical, $inAppAndMail, ['NOC Manager', 'Infrastructure Manager', 'Executive'], false, false],
            ['Assigned to NOC', 'noc_escalation', null, $inAppAndMail, ['NOC Team Leader', 'NOC Tier II', 'NOC Tier I'], false, false],
            ['NOC rejected – CX follow-up', 'noc_rejected', null, $inAppAndMail, ['CX Team Leader'], false, false],
            ['Assigned to Infrastructure', 'infrastructure_escalation', null, $inAppAndMail, ['Infrastructure Team Leader', 'Infrastructure Dispatcher'], false, false],
            ['Dispatched to field team', 'dispatched', null, $inAppAndMail, ['Infrastructure Team Leader'], false, true],
            ['Progress update posted', 'update_posted', null, $inAppAndMail, [], false, true],
            ['Closed by outage technician (restoration reported)', 'restore_reported', null, $inAppAndMail, ['NOC Team Leader', 'NOC Manager'], false, true],
            ['Outage resolved and closed', 'resolved', null, $inAppAndMail, ['NOC Manager', 'CX Team Leader'], false, true],
            ['Assignment rejected – leaders', 'assignment_rejected', null, ['database'], [], false, true],
            ['Queue escalation – managers', 'queue_escalation', null, $inAppAndMail, ['NOC Manager', 'Infrastructure Manager'], false, true],
            ['Credit due – CX', 'credit_due', null, $inAppAndMail, ['CX Manager', 'CX Team Leader'], false, false],
        ] as [$name, $event, $priorityId, $channels, $roles, $assignee, $leaders]) {
            // firstOrCreate: re-seeding on deploy never overwrites rules an administrator has edited.
            NotificationRule::query()->firstOrCreate(['name' => $name], [
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
