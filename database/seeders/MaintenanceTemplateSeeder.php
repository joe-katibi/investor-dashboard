<?php

namespace Database\Seeders;

use App\Models\MaintenanceTemplate;
use Illuminate\Database\Seeder;

/**
 * Default planned-maintenance e-mails (Configuration → Maintenance E-mail Templates). Created only
 * when missing: re-seeding never overwrites wording, recipients or triggers an administrator changed.
 */
class MaintenanceTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::defaults() as $code => $attributes) {
            MaintenanceTemplate::query()->firstOrCreate(['code' => $code], $attributes);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public static function defaults(): array
    {
        $template = fn (string $name, string $trigger, string $subject, string $body, bool $stakeholders, bool $signatories, bool $attach, string $description) => [
            'name' => $name,
            'code' => '',
            'trigger' => $trigger,
            'send_to_stakeholders' => $stakeholders,
            'send_to_signatories' => $signatories,
            'attach_crf' => $attach,
            'subject' => $subject,
            'body' => $body,
            'description' => $description,
            'is_active' => true,
        ];

        $defaults = [
            'crf_issued' => $template('Maintenance notice – CRF for review', 'crf_issued',
                'CRF-{{title}}',
                <<<'TXT'
Hello Team,

Please find attached the Change Request Form (CRF) concerning **{{title}}** ({{ticket}}) for your review and processing.

Additionally, I would like to inform you of the **{{title}}** scheduled to take place on **{{scheduled_date}}, between {{start_time}} and {{end_time}}**.

During this window, please be aware that clients under {{olt_fdt}} will have their service affected right up to the point the work is completed. Estimated clients affected: **{{customers}}**. The compiled list of clients to be affected is attached where available.

Client devices (ONUs) that happen to be powered off during this process will need to be tracked after for service restoration. A list of these will be shared after.

Kindly do share your acknowledgment of this notice and on the attached CRF at your earliest convenience.

Thanks and Regards,
{{department}}
TXT, true, true, true, 'Sent to stakeholders and signatories when NOC issues the CRF for sign-off (CRF attached).'),

            'sign_request' => $template('CRF sign-off request', 'sign_request',
                'Action required: sign CRF {{ticket}} – {{title}}',
                <<<'TXT'
Hello {{signatory_name}},

You are listed as **{{signatory_role}}** on the Maintenance & Change Request Form (CRF) below. Please review and sign.

**{{title}}** ({{ticket}})
Scheduled: **{{scheduled_window}}** (expected downtime {{expected_downtime}})
Area: {{olt_fdt}} | Estimated clients affected: {{customers}}
Risk: {{risk}}
Work: {{work_description}}

{{sign_button}}

The CRF is attached. The maintenance goes ahead only once every signatory has approved it.

Regards,
{{department}}
TXT, false, false, true, 'Personal e-mail to each signatory with their own “Sign CRF” link.'),

            'fully_signed' => $template('CRF fully signed', 'fully_signed',
                'CRF signed off – {{title}} ({{ticket}})',
                <<<'TXT'
Hello Team,

All signatories have approved the Change Request Form for **{{title}}** ({{ticket}}). The fully signed CRF is attached.

**Scheduled window: {{scheduled_window}}**
Area: {{olt_fdt}} | Estimated clients affected: {{customers}}
In-charge / lead: {{in_charge}} | Emergency contact: {{emergency_contact}}

The request has been escalated to Infrastructure dispatch, who will assign the team to implement it.

{{ticket_button}}

Regards,
NOC / Outage Management System
TXT, true, true, true, 'Sent to signatories and stakeholders once every signatory has approved (signed CRF attached).'),

            'changes_requested' => $template('CRF changes requested', 'changes_requested',
                'CRF changes requested – {{title}} ({{ticket}})',
                <<<'TXT'
Hello Team,

**{{signatory_name}}** ({{signatory_role}}) requested changes to the CRF for **{{title}}** ({{ticket}}):

{{reason}}

The CRF has been returned to NOC to update and re-issue for sign-off.

{{ticket_button}}
TXT, true, false, false, 'Sent to stakeholders when a signatory requests changes.'),

            'reminder' => $template('Maintenance reminder', 'reminder',
                'Reminder: {{title}} starts {{time_left}} ({{ticket}})',
                <<<'TXT'
Hello Team,

**Reminder:** the planned maintenance **{{title}}** ({{ticket}}) starts **{{time_left}}**.

**Window: {{scheduled_window}}** (expected downtime {{expected_downtime}})
Area: {{olt_fdt}} | Estimated clients affected: {{customers}}
Team: {{team}} | In-charge / lead: {{in_charge}} | Emergency contact: {{emergency_contact}}

{{ticket_button}}
TXT, true, false, false, 'Sent at each reminder time set in Maintenance Settings (24h, 12h, 6h … 5 minutes before).'),

            'started' => $template('Maintenance started', 'started',
                'STARTED: {{title}} ({{ticket}})',
                <<<'TXT'
Hello Team,

The planned maintenance **{{title}}** ({{ticket}}) has **started**.

**Window: {{scheduled_window}}**
Area: {{olt_fdt}} | Estimated clients affected: {{customers}}
Team: {{team}} | Technician: {{technician}} | Emergency contact: {{emergency_contact}}

Clients in the affected area will experience service interruption until the work is completed.
TXT, true, true, false, 'Sent to everyone (stakeholders and signatories) when the maintenance window starts.'),

            'rescheduled' => $template('Maintenance rescheduled', 'rescheduled',
                'RESCHEDULED: {{title}} – now {{scheduled_window}} ({{ticket}})',
                <<<'TXT'
Hello Team,

Please note that the planned maintenance **{{title}}** ({{ticket}}) has been **rescheduled**.

**New window: {{scheduled_window}}**
Previous window: {{previous_window}}
Reason: {{reason}}

The updated CRF is attached.

Regards,
{{department}}
TXT, true, true, true, 'Sent to stakeholders and signatories when the window changes (updated CRF attached).'),

            'completed' => $template('Maintenance completed', 'completed',
                'COMPLETED: {{title}} ({{ticket}})',
                <<<'TXT'
Hello Team,

The planned maintenance **{{title}}** ({{ticket}}) has been **completed** and customers have been notified.

**Final status: {{final_status}}**
{{outcome_details}}

All services in {{olt_fdt}} have been verified by NOC.

Regards,
NOC / Outage Management System
TXT, true, true, true, 'Sent once CX has notified customers and the ticket is closed (final CRF attached).'),

            'cancelled' => $template('Maintenance cancelled', 'cancelled',
                'CANCELLED: {{title}} ({{ticket}})',
                <<<'TXT'
Hello Team,

The planned maintenance **{{title}}** ({{ticket}}) scheduled for {{scheduled_window}} has been **cancelled**.

Reason: {{reason}}

Regards,
NOC / Outage Management System
TXT, true, true, false, 'Sent when the maintenance is cancelled.'),
        ];

        foreach ($defaults as $code => &$attributes) {
            $attributes['code'] = $code;
        }

        return $defaults;
    }
}
