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

        $this->mobility();
        $this->ticketing();
        $this->productivity();
        $this->summaries();
    }

    /**
     * Management summaries added in October 2026. Created only when missing and inactive until
     * recipients are set, like every other template; safe to run on a system in use:
     *
     *   php artisan db:seed --class=EmailTemplateSeeder --force
     *
     *   Outages            weekly (Monday 07:00, last week) and monthly (the 1st 07:00, last month)
     *   Customer tickets   raised and closed every 2 hours · summary every 6 hours · executive
     *                      summary at 07:00 and 19:00 · final reason report daily at 07:00
     *   Staff productivity every 2 hours (weekly and monthly are in productivity())
     */
    public function summaries(): void
    {
        $noc = [config('mail.from.address') ?: 'noc@example.com'];
        $cx = [config('mail.from.address') ?: 'cx@example.com'];

        foreach ([
            ['outage_weekly_summary', 'Outage Summary – Weekly', 'summary_week', '0 7 * * 1', 168, 'Monday 07:00: the outages of last week (from Monday 00:00), with the Excel list attached.'],
            ['outage_monthly_summary', 'Outage Summary – Monthly', 'summary_month', '0 7 1 * *', 744, 'The 1st at 07:00: the outages of last month, with the Excel list attached.'],
        ] as [$code, $name, $type, $cron, $hours, $description]) {
            $this->seedTemplate($code, [
                'name' => $name,
                'report_type' => $type,
                'schedule_type' => 'cron',
                'cron_expression' => $cron,
                'period_hours' => $hours,
                'to' => $noc,
                'subject' => $name.' – {{period_start}} to {{period_end}}',
                'body' => self::OUTAGE_PERIOD_BODY,
                'attach_excel' => true,
                'is_active' => false,
                'description' => $description,
            ]);
        }

        $this->seedTemplate('ticket_two_hour_update', [
            'name' => 'Tickets – Raised & Closed (every 2 hours)',
            'report_type' => 'ticket_pending',
            'schedule_type' => 'interval',
            'interval_minutes' => 120,
            'period_hours' => 2,
            'to' => $cx,
            'subject' => 'Customer Tickets – raised and closed, {{report_time}} {{report_date}}',
            'body' => self::TICKET_ACTIVITY_BODY,
            'is_active' => false,
            'description' => 'Every 2 hours: the tickets raised and the tickets closed in the last two hours, and where the pending ones stand.',
        ]);

        $this->seedTemplate('ticket_six_hour_summary', [
            'name' => 'Tickets – Summary (every 6 hours)',
            'report_type' => 'ticket_pending',
            'schedule_type' => 'interval',
            'interval_minutes' => 360,
            'period_hours' => 6,
            'to' => $cx,
            'subject' => 'Customer Tickets Summary – {{report_time}} {{report_date}}',
            'body' => self::TICKET_SUMMARY_BODY,
            'attach_excel' => true,
            'is_active' => false,
            'description' => 'Every 6 hours: pending tickets against the SLA per department and region, what came in and what was closed, and the final reasons of the week. Pending list attached.',
        ]);

        $this->seedTemplate('ticket_executive_summary', [
            'name' => 'Tickets – Executive Summary',
            'report_type' => 'ticket_daily',
            'schedule_type' => 'daily_times',
            'daily_times' => ['07:00', '19:00'],
            'period_hours' => 24,
            'to' => $cx,
            'subject' => 'Customer Care Executive Summary – {{report_time}} {{report_date}}',
            'body' => self::TICKET_EXECUTIVE_BODY,
            'is_active' => false,
            'description' => 'At 07:00 and 19:00: the day against the day before, SLA result, pending by SLA and department, the final reasons of the week and the trend.',
        ]);

        $this->seedTemplate('ticket_final_reason_report', [
            'name' => 'Tickets – Final Reason Report',
            'report_type' => 'ticket_final_reasons',
            'schedule_type' => 'daily_times',
            'daily_times' => ['07:00'],
            'period_hours' => 168,
            'to' => $cx,
            'subject' => 'Final Reason Report – 7 days to {{tk_reasons_until}}',
            'body' => self::TICKET_FINAL_REASON_BODY,
            'attach_excel' => true,
            'is_active' => false,
            'description' => 'Daily at 07:00: what tickets and site visits were closed with, day by day for seven days against the seven days before. Report attached.',
        ]);

        $this->seedTemplate('productivity_two_hourly', [
            'name' => 'Staff Productivity – Every 2 Hours',
            'report_type' => 'productivity',
            'schedule_type' => 'interval',
            'interval_minutes' => 120,
            'period_hours' => 2,
            'to' => [config('mail.from.address') ?: 'management@example.com'],
            'subject' => 'Staff Productivity – {{productivity_period}}',
            'body' => self::PRODUCTIVITY_BODY,
            'attach_excel' => true,
            'is_active' => false,
            'description' => 'Every 2 hours: what each person did in the last two hours, in every module.',
        ]);
    }

    public const OUTAGE_PERIOD_BODY = <<<'TXT'
Hello Team,

Outage summary for **{{period_start}} to {{period_end}}** (Excel copy attached).

**Outages reported: {{new_outages}} | Resolved: {{resolved_outages}} | Currently active: {{active_outages}} | SLA breached now: {{sla_breached}} | Customers affected (active): {{customers_affected}}**

**7-DAY TREND**
{{trend_table}}

**TOP RISK AREAS**
{{top_olts_table}}
{{top_services_table}}

**OUTAGES OF THE PERIOD**
{{summary_table}}

Regards,
NOC / Outage Management System
TXT;

    public const TICKET_ACTIVITY_BODY = <<<'TXT'
Hello Team,

Customer tickets and site visits as at **{{report_time}}, {{report_date}}** — the last {{tk_period_hours}} hours.

{{tk_headline_table}}

**RAISED IN THE LAST {{tk_period_hours}} HOURS** ({{tk_raised}})
{{tk_raised_table}}

**CLOSED IN THE LAST {{tk_period_hours}} HOURS** ({{tk_closed}}, {{tk_closed_sla_pct}} within SLA)
{{tk_closed_table}}

**PENDING PER DEPARTMENT** (by how long they have been open)
{{tk_sla_per_department_table}}

Regards,
Customer Care / Outage Management System
TXT;

    public const TICKET_SUMMARY_BODY = <<<'TXT'
Hello Team,

Customer care summary as at **{{report_time}}, {{report_date}}**. SLA clock: {{tk_sla_clock}}.

{{tk_headline_table}}

**In the last {{tk_period_hours}} hours: {{tk_raised}} raised · {{tk_closed}} closed ({{tk_closed_sla_pct}} within SLA)**

**SLA PER DEPARTMENT** (pending tickets by how long they have been open)
{{tk_sla_per_department_table}}

**SLA PER REGION**
{{tk_sla_per_region_table}}

**IN CUSTOMER FOLLOW-UP** (pending past the follow-up time — ready for credit)
{{tk_follow_up_table}}

**FINAL REASONS — LAST 7 DAYS** ({{tk_reasons_total}} closed, {{tk_reasons_previous}} the week before)
{{tk_final_reasons_table}}

The list of pending tickets is attached.

Regards,
Customer Care / Outage Management System
TXT;

    public const TICKET_EXECUTIVE_BODY = <<<'TXT'
**CUSTOMER CARE EXECUTIVE SUMMARY**
Reporting time: {{report_time}} | {{report_date}} — {{tk_period_label}} compared with {{tk_previous_label}}. SLA clock: {{tk_sla_clock}}.

**1. HEADLINE**
{{tk_headline_table}}

**2. TICKET MOVEMENT** — what came in, what was closed, what is left
{{tk_movement_table}}

**3. SLA** — closed tickets, and where the pending ones stand
{{tk_closed_sla_table}}
{{tk_pending_sla_table}}

**4. BY DEPARTMENT**
{{tk_sla_per_department_table}}

**5. FINAL REASONS — LAST 7 DAYS** ({{tk_reasons_total}} closed, {{tk_reasons_previous}} the week before)
{{tk_final_reasons_table}}

**6. REASONS FOR PENDING**
{{tk_pending_reasons_table}}

**7. TREND**
{{tk_inflow_chart_table}}
{{tk_sla_chart_table}}

Regards,
Customer Care / Outage Management System
TXT;

    public const TICKET_FINAL_REASON_BODY = <<<'TXT'
Hello Team,

What customer tickets and site visits were closed with — the seven days to **{{tk_reasons_until}}**.

**{{tk_reasons_total}} closed in these seven days, {{tk_reasons_previous}} in the seven days before.**

{{tk_final_reasons_table}}

“Change” compares each reason with the seven days before; a reason that keeps rising deserves a look. The report is attached.

Regards,
Customer Care / Outage Management System
TXT;

    /**
     * Staff productivity e-mails across every module: hourly (last hour), daily at 19:00 (the
     * day so far), weekly on Monday 07:00 (last week) and monthly on the 1st at 07:00 (last month).
     * Created only when missing, inactive until recipients are set.
     */
    public function productivity(): void
    {
        $recipients = [config('mail.from.address') ?: 'management@example.com'];

        foreach ([
            ['productivity_hourly', 'Staff Productivity – Hourly', 'productivity', ['schedule_type' => 'interval', 'interval_minutes' => 60], 1,
                'Every hour: what each person did in the last hour, in every module.'],
            ['productivity_daily', 'Staff Productivity – Daily', 'productivity_day', ['schedule_type' => 'daily_times', 'daily_times' => ['19:00']], 24,
                'At 19:00: the day so far per person and module (sent before 09:00 it covers yesterday).'],
            ['productivity_weekly', 'Staff Productivity – Weekly', 'productivity_week', ['schedule_type' => 'cron', 'cron_expression' => '0 7 * * 1'], 168,
                'Monday 07:00: the week just ended (Monday to Sunday).'],
            ['productivity_monthly', 'Staff Productivity – Monthly', 'productivity_month', ['schedule_type' => 'cron', 'cron_expression' => '0 7 1 * *'], 744,
                'The 1st at 07:00: the month just ended.'],
        ] as [$code, $name, $type, $schedule, $hours, $description]) {
            $this->seedTemplate($code, $schedule + [
                'name' => $name,
                'report_type' => $type,
                'period_hours' => $hours,
                'to' => $recipients,
                'subject' => $name.' – {{productivity_period}}',
                'body' => self::PRODUCTIVITY_BODY,
                'attach_excel' => true,
                'is_active' => false,
                'description' => $description,
            ]);
        }
    }

    public const PRODUCTIVITY_BODY = <<<'TXT'
Hello Team,

Staff productivity for **{{productivity_period}}** — outages, 4G outages, customer tickets and site visits.

**{{productivity_people}} people · {{productivity_worked}} tickets worked · {{productivity_closed}} closed · {{productivity_escalated}} escalated**

**BY MODULE**
{{productivity_module_table}}

**BY PERSON**
{{productivity_table}}

**FIELD WORK** (restorations reported, visits completed)
{{productivity_field_table}}

Worked = tickets the person accepted, completed a step on, closed, escalated or restored. Close rate and escalation rate are closed ÷ worked and escalated ÷ worked. The full report is attached.

Regards,
Outage Management System
TXT;

    /**
     * Customer ticket e-mails (dashboard tables and charts in the mail): the pending / SLA update
     * every 3 hours from 07:00 to 19:00, the daily report at 07:00, 12:00 and 19:00, the weekly
     * report on Monday 07:00 and the monthly report on the 1st at 07:00. Times and recipients are
     * edited under Configuration → Email Templates. Created only when missing, inactive.
     */
    public function ticketing(): void
    {
        $recipients = [config('mail.from.address') ?: 'cx@example.com'];

        $this->seedTemplate('ticket_pending_update', [
            'name' => 'Tickets – Pending / SLA Update',
            'report_type' => 'ticket_pending',
            'schedule_type' => 'daily_times',
            'daily_times' => ['07:00', '10:00', '13:00', '16:00', '19:00'],
            'period_hours' => 3,
            'to' => $recipients,
            'subject' => 'Pending Tickets & SLA – {{report_time}} {{report_date}}',
            'body' => self::TICKET_PENDING_BODY,
            'attach_excel' => true,
            'is_active' => false,
            'description' => 'Every 3 hours, 07:00–19:00: pending tickets by SLA per department, OLT and region, issues per OLT, tickets in customer follow-up and what was closed. Pending list attached.',
        ]);

        $this->seedTemplate('ticket_daily_report', [
            'name' => 'Tickets – Daily Report',
            'report_type' => 'ticket_daily',
            'schedule_type' => 'daily_times',
            'daily_times' => ['07:00', '12:00', '19:00'],
            'period_hours' => 24,
            'to' => $recipients,
            'subject' => 'Daily Ticket Report – {{tk_period_label}}',
            'body' => self::TICKET_PERIOD_BODY,
            'is_active' => false,
            'description' => 'Three times a day: ticket movement, closed within SLA, pending by SLA, inflow vs closed and SLA % charts, regions and reasons for pending.',
        ]);

        $this->seedTemplate('ticket_weekly_report', [
            'name' => 'Tickets – Weekly Report',
            'report_type' => 'ticket_weekly',
            'schedule_type' => 'cron',
            'cron_expression' => '0 7 * * 1',
            'period_hours' => 168,
            'to' => $recipients,
            'subject' => 'Weekly Ticket Report – {{tk_period_label}}',
            'body' => self::TICKET_PERIOD_BODY,
            'is_active' => false,
            'description' => 'Every Monday 07:00: the week just ended (Monday to Sunday) against the week before, with the last 12 weeks.',
        ]);

        $this->seedTemplate('ticket_monthly_report', [
            'name' => 'Tickets – Monthly Report',
            'report_type' => 'ticket_monthly',
            'schedule_type' => 'cron',
            'cron_expression' => '0 7 1 * *',
            'period_hours' => 744,
            'to' => $recipients,
            'subject' => 'Monthly Ticket Report – {{tk_period_label}}',
            'body' => self::TICKET_PERIOD_BODY,
            'is_active' => false,
            'description' => 'Every 1st of the month 07:00: the month just ended against the month before, with the last 12 months.',
        ]);
    }

    public const TICKET_PENDING_BODY = <<<'TXT'
Hello Team,

Ticket status as at **{{report_time}}, {{report_date}}**. SLA clock: {{tk_sla_clock}}.

{{tk_headline_table}}

**SLA PER DEPARTMENT** (pending tickets by how long they have been open)
{{tk_sla_per_department_table}}

**SLA PER OLT**
{{tk_sla_per_olt_table}}

**ISSUE PER OLT**
{{tk_issue_per_olt_table}}

**SLA PER REGION**
{{tk_sla_per_region_table}}

**IN CUSTOMER FOLLOW-UP** (pending past the follow-up time — ready for credit)
{{tk_follow_up_table}}

**CLOSED IN THE LAST {{tk_period_hours}} HOURS**
{{tk_closed_table}}

Regards,
Customer Care / Outage Management System
TXT;

    public const TICKET_PERIOD_BODY = <<<'TXT'
**{{tk_report_title}} — {{tk_period_label}}**
Compared with {{tk_previous_label}}. SLA clock: {{tk_sla_clock}}.

{{tk_headline_table}}

**TICKET MOVEMENT** — what came in, what was closed, what is left
{{tk_movement_table}}

**CLOSED TICKETS** — SLA result
{{tk_closed_sla_table}}

**PENDING TICKETS** — where they stand against the SLA
{{tk_pending_sla_table}}

**INFLOW vs CLOSED**
{{tk_inflow_chart_table}}

**CLOSED WITHIN SLA %**
{{tk_sla_chart_table}}

**BY REGION**
{{tk_region_table}}

**BY DEPARTMENT**
{{tk_sla_per_department_table}}

**REASONS FOR PENDING**
{{tk_pending_reasons_table}}

**TREND**
{{tk_trend_table}}

Regards,
Customer Care / Outage Management System
TXT;

    /** The hourly 4G update. Created only when missing (inactive until recipients are set). */
    public function mobility(): void
    {
        $recipients = [config('mail.from.address') ?: 'noc@example.com'];

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
