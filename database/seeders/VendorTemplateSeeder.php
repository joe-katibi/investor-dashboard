<?php

namespace Database\Seeders;

use App\Models\VendorTemplate;
use Illuminate\Database\Seeder;

/**
 * Default vendor e-mails (4G NOC → Vendor E-mail Templates). Created only when missing:
 * re-seeding never overwrites wording, recipients or triggers an administrator changed.
 */
class VendorTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::defaults() as $code => $attributes) {
            VendorTemplate::query()->firstOrCreate(['code' => $code], $attributes);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public static function defaults(): array
    {
        $template = fn (string $name, string $trigger, string $subject, string $body, string $description) => [
            'name' => $name,
            'trigger' => $trigger,
            'send_to_vendor' => true,
            'cc_vendor_cc' => true,
            'subject' => $subject,
            'body' => $body,
            'description' => $description,
            'is_active' => true,
        ];

        return [
            'power_vendor_notification' => $template('4G Power Outage – Vendor Notification', VendorTemplate::TICKET_CREATED,
                '[4G POWER OUTAGE] {{ticket_number}} - {{site_name}}',
                <<<'TXT'
Dear {{vendor_name}} team,

We have a power outage affecting our 4G site below. Kindly attend to it urgently and share an acknowledgement with your ETA.

**Ticket number:** {{ticket_number}}
**Site ID:** {{site_id}}
**Site name:** {{site_name}}
**Affected sites:** {{affected_sites}}
**Location:** {{location}}
**Outage type:** {{outage_type}}
**Priority:** {{priority}}
**Outage start time:** {{outage_start_time}}
**Detection time:** {{detection_time}}
**Affected services:** {{affected_services}}
**Expected restoration (SLA):** within {{sla}} — by {{sla_due}}

**Description:**
{{description}}

Please reply to this e-mail or call the NOC with your updates, quoting **{{ticket_number}}**.

**NOC contact:** {{noc_contact}}

Regards,
4G NOC
TXT, 'Sent automatically to the selected vendor when a Power Outage ticket is created. Also used for “Send vendor notification” and when the vendor is changed, unless those have their own template.'),

            'vendor_reminder' => $template('4G Power Outage – Vendor Reminder', VendorTemplate::REMINDER,
                '[REMINDER] [4G POWER OUTAGE] {{ticket_number}} - {{site_name}}',
                <<<'TXT'
Dear {{vendor_name}} team,

We have not received an update on the power outage below. {{reason}}

**Ticket number:** {{ticket_number}}
**Site:** {{site_id}} {{site_name}}
**Outage start time:** {{outage_start_time}}
**Outage duration so far:** {{outage_duration}}
**Priority:** {{priority}}
**Expected restoration (SLA):** by {{sla_due}}

Kindly share the current status and ETA, quoting **{{ticket_number}}**.

**NOC contact:** {{noc_contact}}

Regards,
4G NOC
TXT, 'Sent to the vendor when no vendor update has been recorded within the management escalation time (4G NOC → 4G Settings).'),

            'vendor_reopened' => $template('4G Power Outage – Reopened', VendorTemplate::REOPENED,
                '[REOPENED] [4G POWER OUTAGE] {{ticket_number}} - {{site_name}}',
                <<<'TXT'
Dear {{vendor_name}} team,

The ticket below has been reopened: the site is not (fully) restored.

**Reason:** {{reason}}

**Ticket number:** {{ticket_number}}
**Site:** {{site_id}} {{site_name}}
**Location:** {{location}}
**Outage start time:** {{outage_start_time}}
**Priority:** {{priority}}

Kindly attend to it urgently and share your ETA, quoting **{{ticket_number}}**.

**NOC contact:** {{noc_contact}}

Regards,
4G NOC
TXT, 'Sent to the vendor when the NOC reopens a closed vendor ticket.'),

            'vendor_closed' => $template('4G Power Outage – Restored and Closed', VendorTemplate::CLOSED,
                '[RESTORED] [4G POWER OUTAGE] {{ticket_number}} - {{site_name}}',
                <<<'TXT'
Dear {{vendor_name}} team,

We confirm that the site below is restored and the ticket is now closed. Thank you for your support.

**Ticket number:** {{ticket_number}}
**Site:** {{site_id}} {{site_name}}
**Outage start time:** {{outage_start_time}}
**Restoration time:** {{restoration_time}}
**Outage duration:** {{outage_duration}}
**Root cause:** {{root_cause}}
**Resolution:** {{resolution}}

Regards,
4G NOC
TXT, 'Sent to the vendor when the NOC has verified the restoration and closed the ticket. Deactivate it if vendors should not get a closure e-mail.'),

            'vendor_cancelled' => $template('4G Power Outage – Cancelled', VendorTemplate::CANCELLED,
                '[CANCELLED] [4G POWER OUTAGE] {{ticket_number}} - {{site_name}}',
                <<<'TXT'
Dear {{vendor_name}} team,

Please note that ticket **{{ticket_number}}** for site {{site_id}} {{site_name}} has been cancelled. No further action is required.

**Reason:** {{reason}}

Regards,
4G NOC
TXT, 'Sent to the vendor when a ticket they were notified about is cancelled.'),
        ];
    }
}
