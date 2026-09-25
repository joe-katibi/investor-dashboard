<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

/**
 * Scheduled e-mail templates (spec §13–§17). Seeded INACTIVE: set recipients and activate
 * under Configuration → Email Templates & Schedules.
 */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $recipients = [config('mail.from.address') ?: 'noc@example.com'];

        EmailTemplate::query()->updateOrCreate(['code' => 'two_hour_update'], [
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

        EmailTemplate::query()->updateOrCreate(['code' => 'executive_summary'], [
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

        EmailTemplate::query()->updateOrCreate(['code' => 'summary_report'], [
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
    }

    public const TWO_HOUR_BODY = <<<'TXT'
Hello Team,

Outage updates as at {{report_time}} {{report_date}}

EXECUTIVE SNAPSHOT
• Active outages: {{active_outages}}
• Critical outages: {{critical_outages}}
• Customers affected: {{customers_affected}}
• SLA at risk: {{sla_at_risk}}
• SLA breached: {{sla_breached}}
• SMS sent: {{sms_sent}}
• Credits shared: {{credits_shared}}

LIVE NETWORK OUTAGES – FTTH
{{#for each active network outage}}
{{ticket}} | {{location}} | {{olt_fdt}} | Start: {{start_time}}
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
4G MOBILITY
• Total sites affected: {{sites_down}}
• Total cells down: {{cells_down}}
• Power faults: {{power_faults}}
• Other major 4G incidents: {{other_4g}}

CORE OUTAGES
{{core_summary_or_no_core_outages}}

SYSTEM OUTAGES
{{system_summary_or_no_system_outages}}

Regards,
NOC / Outage Management System
TXT;

    public const EXECUTIVE_BODY = <<<'TXT'
OUTAGE MANAGEMENT EXECUTIVE SUMMARY
Reporting time: {{report_time}} | {{report_date}}

1. EXECUTIVE SNAPSHOT
Active: {{active_outages}} | New: {{new_outages}} | Resolved: {{resolved_outages}} | Critical: {{critical_outages}} | Customers affected: {{customers_affected}} | SLA breached: {{sla_breached}} | SLA at risk: {{sla_at_risk}}

2. MAJOR ACTIVE INCIDENTS
{{#for each major incident}}
{{ticket}} | {{location}} | OLT/FDT: {{olt_fdt}}
Start: {{start_time}} | Initial impact: {{customers}} clients
NOC escalation: {{noc_time}} | Infrastructure escalation: {{maintenance_time}}
SMS: {{sms_status}} | Credit: {{credit_status}} | Status: {{status}} | Owner: {{owner}}
Latest action: {{latest_action}}

{{/for}}
3. 4G MOBILITY
Sites affected: {{sites_down}} | Cells down: {{cells_down}} | Power faults: {{power_faults}}

4. CORE OUTAGES
{{core_summary_or_no_core_outages}}

5. SYSTEM OUTAGES
{{system_summary_or_no_system_outages}}

6. SLA / CUSTOMER EXPERIENCE
At risk: {{sla_at_risk}} | Breached: {{sla_breached}} | Credits shared: {{credits_shared}} | SMS sent: {{sms_sent}} | Oldest active: {{oldest_active}}

7. MANAGEMENT ATTENTION REQUIRED
{{major_incidents_text}}

8. 7-DAY TREND
{{trend_table}}

9. TOP RISK AREAS
{{top_olts_table}}
{{top_services_table}}

10. RESTORED / CLOSED
{{closed_summary}}
{{#for each closed outage}}
{{ticket}} | {{title}} | Restored {{end_time}} | RFO: {{final_reason}}
{{/for}}
TXT;

    public const SUMMARY_BODY = <<<'TXT'
Hello Team,

Please find the outage summary for {{period_start}} to {{period_end}} below (Excel copy attached).

Outages reported: {{new_outages}} | Resolved: {{resolved_outages}} | Currently active: {{active_outages}} | Customers affected (active): {{customers_affected}}

{{summary_table}}

Regards,
NOC / Outage Management System
TXT;
}
