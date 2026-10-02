<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

/**
 * Scheduled e-mail templates (spec §13–§17). Created INACTIVE: set recipients and activate
 * under Configuration → Email Templates & Schedules.
 */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $recipients = [config('mail.from.address') ?: 'noc@example.com'];

        $this->seedTemplate('two_hour_update', [
            'name' => 'Two-Hour Outage Update',
            'report_type' => 'two_hour',
            'schedule_type' => 'interval',
            'interval_minutes' => 120,
            'period_hours' => 2,
            'to' => $recipients,
            'subject' => 'Outage Updates – {{report_time}} {{report_date}}',
            'body' => self::TWO_HOUR_BODY,
            'is_active' => false,
            'description' => 'Current live outage status and actions (spec §15). Activate after setting recipients.',
        ]);

        $this->seedTemplate('executive_summary', [
            'name' => 'Executive Summary',
            'report_type' => 'executive',
            'schedule_type' => 'daily_times',
            'daily_times' => ['00:00', '06:00', '12:00', '18:00'],
            'period_hours' => 6,
            'to' => $recipients,
            'subject' => 'Outage Management Executive Summary – {{report_time}} {{report_date}}',
            'body' => self::EXECUTIVE_BODY,
            'is_active' => false,
            'description' => 'Executive command-centre summary (spec §14 / §16).',
        ]);

        $this->seedTemplate('summary_report', [
            'name' => 'Summary Report',
            'report_type' => 'summary',
            'schedule_type' => 'daily_times',
            'daily_times' => ['07:00'],
            'period_hours' => 24,
            'to' => $recipients,
            'subject' => 'Outage Summary Report – {{period_start}} to {{period_end}}',
            'body' => self::SUMMARY_BODY,
            'attach_excel' => true,
            'is_active' => false,
            'description' => 'Structured table of outages for the reporting period (spec §17), with Excel attachment.',
        ]);

        $this->seedTemplate('mobility_hourly_update', [
            'name' => '4G Hourly Outage Update',
            'report_type' => 'mobility_hourly',
            'schedule_type' => 'interval',
            'interval_minutes' => 60,
            'period_hours' => 1,
            'to' => $recipients,
            'subject' => '4G Outage Update – {{report_time}} {{report_date}}',
            'body' => self::MOBILITY_HOURLY_BODY,
            'is_active' => false,
            'description' => 'Hourly status of every open 4G outage (eNodeB, transmission, power) with the latest engineer / vendor update. Activate after setting recipients.',
        ]);
    }

    /**
     * Create the template if it is missing. An existing template belongs to the administrator
     * (recipients, schedule, activation, wording) and is never overwritten — the body is only
     * upgraded when it is still the untouched previous default (the same text without the
     * **bold** markers added later).
     */
    private function seedTemplate(string $code, array $attributes): void
    {
        $template = EmailTemplate::query()->where('code', $code)->first();

        if (! $template) {
            EmailTemplate::query()->create(['code' => $code] + $attributes);

            return;
        }

        $normalise = fn (?string $text) => trim(str_replace("\r\n", "\n", (string) $text));

        if ($normalise($template->body) === $normalise(str_replace('**', '', $attributes['body']))) {
            $template->forceFill(['body' => $attributes['body']])->save();
        }
    }

    public const MOBILITY_HOURLY_BODY = <<<'TXT'
Hello Team,

4G outage update as at {{report_time}} {{report_date}}

**4G SNAPSHOT**
• Open 4G outages: {{g4_active}} (Critical {{g4_critical}} | Major {{g4_major}} | Minor {{g4_minor}})
• eNodeB: {{g4_enodeb}} | Transmission: {{g4_transmission}} | Power: {{g4_power}}
• eNodeBs (sites) affected: {{g4_sites_down}} | Cells down: {{g4_cells_down}}
• With vendors: {{g4_with_vendor}} | Resolved – pending NOC confirmation: {{g4_pending_noc}}
• SLA approaching: {{g4_sla_at_risk}} | SLA breached: {{g4_sla_breached}}
• New this hour: {{g4_new}} | Closed this hour: {{g4_closed}}

**OPEN 4G OUTAGES**
{{#for each active 4G outage}}
**{{ticket}} | {{category}} | {{severity}} | {{site}}**
{{title}}
Start: {{start_time}} | Open for: {{elapsed}} | Affected: {{impact}}
Status: {{status}} | With: {{owner}}
SLA: {{sla_status}} | Due: {{sla_due}} | Vendor ETA: {{vendor_eta}}
Latest update:
{{latest_action}}

{{/for}}
**CLOSED THIS HOUR**
{{#for each closed 4G outage}}
**{{ticket}}** | {{category}} | {{site}} | Restored {{end_time}} | Duration {{duration}} | Root cause: {{root_cause}}
{{/for}}

Regards,
4G NOC / Outage Management System
TXT;

    public const TWO_HOUR_BODY = <<<'TXT'
Hello Team,

Outage updates as at {{report_time}} {{report_date}}

**EXECUTIVE SNAPSHOT**
• Active outages: {{active_outages}}
• Critical outages: {{critical_outages}}
• Customers affected: {{customers_affected}}
• SLA at risk: {{sla_at_risk}}
• SLA breached: {{sla_breached}}
• SMS sent: {{sms_sent}}
• Credits shared: {{credits_shared}}

**LIVE NETWORK OUTAGES – FTTH**
{{#for each active network outage}}
**{{ticket}} | {{location}} | {{olt_fdt}} | Start: {{start_time}}**
Initial impact: {{customers}} clients
Escalated to NOC: {{noc_time}}
Escalated to Maintenance/Infrastructure: {{maintenance_time}}
SMS: {{sms_status}}
Credit: {{credit_status}}
SLA: {{sla_status}}
Status: {{status}}
Action / Latest Update:
{{latest_action}}

{{/for}}
**4G MOBILITY**
• Total sites affected: {{sites_down}}
• Total cells down: {{cells_down}}
• Power faults: {{power_faults}}
• Other major 4G incidents: {{other_4g}}

**CORE OUTAGES**
{{core_summary_or_no_core_outages}}

**SYSTEM OUTAGES**
{{system_summary_or_no_system_outages}}

Regards,
NOC / Outage Management System
TXT;

    public const EXECUTIVE_BODY = <<<'TXT'
**OUTAGE MANAGEMENT EXECUTIVE SUMMARY**
Reporting time: {{report_time}} | {{report_date}}

**1. EXECUTIVE SNAPSHOT**
Active: {{active_outages}} | New: {{new_outages}} | Resolved: {{resolved_outages}} | Critical: {{critical_outages}} | Customers affected: {{customers_affected}} | SLA breached: {{sla_breached}} | SLA at risk: {{sla_at_risk}}

**2. MAJOR ACTIVE INCIDENTS**
{{#for each major incident}}
**{{ticket}} | {{location}} | OLT/FDT: {{olt_fdt}}**
Start: {{start_time}} | Initial impact: {{customers}} clients
NOC escalation: {{noc_time}} | Infrastructure escalation: {{maintenance_time}}
SMS: {{sms_status}} | Credit: {{credit_status}} | Status: {{status}} | Owner: {{owner}}
Latest action: {{latest_action}}

{{/for}}
**3. 4G MOBILITY**
Sites affected: {{sites_down}} | Cells down: {{cells_down}} | Power faults: {{power_faults}}

**4. CORE OUTAGES**
{{core_summary_or_no_core_outages}}

**5. SYSTEM OUTAGES**
{{system_summary_or_no_system_outages}}

**6. SLA / CUSTOMER EXPERIENCE**
At risk: {{sla_at_risk}} | Breached: {{sla_breached}} | Credits shared: {{credits_shared}} | SMS sent: {{sms_sent}} | Oldest active: {{oldest_active}}

**7. MANAGEMENT ATTENTION REQUIRED**
{{major_incidents_text}}

**8. 7-DAY TREND**
{{trend_table}}

**9. TOP RISK AREAS**
{{top_olts_table}}
{{top_services_table}}

**10. RESTORED / CLOSED**
{{closed_summary}}
{{#for each closed outage}}
**{{ticket}}** | {{title}} | Restored {{end_time}} | RFO: {{final_reason}}
{{/for}}
TXT;

    public const SUMMARY_BODY = <<<'TXT'
Hello Team,

Please find the outage summary for {{period_start}} to {{period_end}} below (Excel copy attached).

**Outages reported: {{new_outages}} | Resolved: {{resolved_outages}} | Currently active: {{active_outages}} | Customers affected (active): {{customers_affected}}**

{{summary_table}}

Regards,
NOC / Outage Management System
TXT;
}
