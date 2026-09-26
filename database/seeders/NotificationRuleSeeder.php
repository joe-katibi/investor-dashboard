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

        // Lifecycle alerts: in-app, e-mail and SMS (SMS reaches users who have a phone number on their profile).
        $all = ['database', 'mail', 'sms'];
        $inAppAndMail = ['database', 'mail'];

        // Specific extra e-mail addresses / phone numbers are managed on
        // Configuration → Alert E-mails (they receive every selected event).
        // [name, event, priority filter, channels, recipient roles, notify assignee, notify team leaders]
        foreach ([
            ['Ticket created – NOC', 'outage_created', null, $all, ['NOC Manager', 'NOC Team Leader'], false, false],
            ['Critical outage created', 'outage_created', $critical, $all, ['NOC Manager', 'Infrastructure Manager', 'Executive'], false, false],
            ['Assigned to NOC', 'noc_escalation', null, $all, ['NOC Team Leader', 'NOC Tier II', 'NOC Tier I'], false, false],
            ['NOC rejected – CX follow-up', 'noc_rejected', null, $all, ['CX Team Leader'], false, false],
            ['Assigned to Infrastructure', 'infrastructure_escalation', null, $all, ['Infrastructure Team Leader', 'Infrastructure Dispatcher'], false, false],
            ['Dispatched to field team', 'dispatched', null, $all, ['Infrastructure Team Leader'], false, true],
            ['Progress update posted', 'update_posted', null, $all, [], false, true],
            ['Closed by outage technician (restoration reported)', 'restore_reported', null, $all, ['NOC Team Leader', 'NOC Manager'], false, true],
            ['Outage resolved and closed', 'resolved', null, $all, ['NOC Manager', 'CX Team Leader'], false, true],
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
