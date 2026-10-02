<?php

namespace Database\Seeders;

use App\Models\MajorOutageType;
use App\Models\NotificationRule;
use App\Models\Priority;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

/** Default notification rules (spec §12). Requires PrioritySeeder and RolesAndPermissionsSeeder. */
class NotificationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $critical = Priority::query()->where('code', Priority::CRITICAL)->value('id');
        $system = MajorOutageType::query()->where('code', MajorOutageType::SYSTEM)->value('id');

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
            // NOC reject → CX Tier II callback / site visit
            ['CX follow-up recorded', 'cx_follow_up', null, $inAppAndMail, ['CX Team Leader', 'NOC Team Leader'], false, false],
            ['Site visit raised', 'site_visit_raised', null, $all, ['Dispatch Team Leader', 'Dispatch Manager', 'CX Team Leader'], false, false],
            // Support technician on site, in parallel with the outage technician
            ['Support technician requested', 'support_requested', null, $all, ['Dispatch Team Leader', 'Dispatch Manager'], false, true],
            ['Support technician work completed', 'support_completed', null, $all, ['Infrastructure Team Leader'], false, true],
            // System outages: IT first
            ['Assigned to IT', 'it_escalation', null, $all, ['IT Team Leader', 'IT Manager'], false, false],
            ['NOC resolved – returned to IT', 'returned_to_it', null, $all, ['IT Team Leader'], false, false],
            ['System outage resolved – IT feedback', 'resolved', $system, $all, ['IT Manager', 'IT Team Leader', 'NOC Manager', 'CX Team Leader'], false, false, 'major'],
            // Administrators follow every ticket update in the bell (in-app only, no e-mail/SMS noise).
            ['Admin feed – ticket created', 'outage_created', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – assigned to NOC', 'noc_escalation', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – NOC rejected', 'noc_rejected', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – assigned to Infrastructure', 'infrastructure_escalation', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – dispatched', 'dispatched', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – progress update', 'update_posted', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – closed by outage technician', 'restore_reported', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – resolved', 'resolved', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – CX follow-up', 'cx_follow_up', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – support technician requested', 'support_requested', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – support work completed', 'support_completed', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – assigned to IT', 'it_escalation', null, ['database'], ['Super Admin'], false, false],
            ['Admin feed – returned to IT', 'returned_to_it', null, ['database'], ['Super Admin'], false, false],
        ] as $row) {
            // Optional 8th element: 'major' means the 3rd element filters by major outage type instead of priority.
            [$name, $event, $filterId, $channels, $roles, $assignee, $leaders] = $row;
            $byMajor = ($row[7] ?? null) === 'major';

            // firstOrCreate: re-seeding on deploy never overwrites rules an administrator has edited.
            NotificationRule::query()->firstOrCreate(['name' => $name], [
                'event' => $event,
                'priority_id' => $byMajor ? null : $filterId,
                'major_outage_type_id' => $byMajor ? $filterId : null,
                'channels' => $channels,
                'recipient_roles' => $roles,
                'notify_assignee' => $assignee,
                'notify_team_leaders' => $leaders,
                'is_active' => true,
            ]);
        }

        $this->mobility();
    }

    /**
     * 4G NOC rules. A 4G ticket only fires rules whose vertical is 4G, so these are the whole
     * audience of a 4G ticket (plus the 4G alert lists under 4G NOC → 4G Settings).
     * Created only when missing, so edited rules survive re-seeding.
     */
    public function mobility(): void
    {
        $vertical = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');

        if (! $vertical) {
            return;
        }

        $all = ['database', 'mail', 'sms'];
        $inAppAndMail = ['database', 'mail'];

        $critical = Priority::query()->where('code', Priority::MOBILITY_CRITICAL)->value('id');
        $noc = ['4G NOC', '4G NOC Manager'];
        $inApp = ['database'];

        // [name, event, priority filter, channels, recipient roles, notify assignee]
        foreach ([
            ['4G – ticket created', 'outage_created', null, $all, $noc, false],
            ['4G – critical ticket created', 'outage_created', $critical, $all, ['4G NOC Manager', 'Executive'], false],
            ['4G – progress update posted', 'update_posted', null, $inApp, ['4G NOC'], true],
            ['4G – vendor notified by e-mail', 'mobility_vendor_notified', null, $inApp, ['4G NOC'], false],
            ['4G – vendor notification failed', 'mobility_vendor_mail_failed', null, $inAppAndMail, $noc, false],
            ['4G – vendor update recorded', 'mobility_vendor_update', null, $inApp, ['4G NOC Manager'], false],
            ['4G – no vendor update (NOC reminder)', 'mobility_vendor_silent', null, $inAppAndMail, ['4G NOC'], false],
            ['4G – no vendor update (management)', 'mobility_vendor_escalation', null, $all, ['4G NOC Manager'], false],
            ['4G – resolved, pending NOC confirmation', 'mobility_resolved_pending', null, $all, ['4G NOC'], false],
            ['4G – NOC could not verify the fix', 'mobility_verification_failed', null, $inAppAndMail, ['4G NOC Manager'], true],
            ['4G – verified and closed', 'resolved', null, $all, ['4G NOC Manager'], false],
            ['4G – ticket reopened', 'mobility_reopened', null, $all, $noc, false],
            ['4G – ticket cancelled', 'mobility_cancelled', null, $inAppAndMail, ['4G NOC Manager'], false],
            ['4G – escalated by NOC', 'mobility_escalated', null, $all, ['4G NOC Manager'], false],
            ['4G – SLA approaching breach', 'sla_pre_breach', null, $inAppAndMail, $noc, true],
            ['4G – SLA breached', 'sla_breach', null, $all, ['4G NOC Manager'], true],
            ['4G admin feed – ticket created', 'outage_created', null, $inApp, ['Super Admin'], false],
            ['4G admin feed – resolved, pending NOC', 'mobility_resolved_pending', null, $inApp, ['Super Admin'], false],
            ['4G admin feed – closed', 'resolved', null, $inApp, ['Super Admin'], false],
            ['4G admin feed – reopened', 'mobility_reopened', null, $inApp, ['Super Admin'], false],
        ] as [$name, $event, $priorityId, $channels, $roles, $assignee]) {
            NotificationRule::query()->firstOrCreate(['name' => $name], [
                'event' => $event,
                'vertical_id' => $vertical,
                'priority_id' => $priorityId,
                'channels' => $channels,
                'recipient_roles' => $roles,
                'notify_assignee' => $assignee,
                'notify_team_leaders' => false,
                'is_active' => true,
            ]);
        }
    }
}
