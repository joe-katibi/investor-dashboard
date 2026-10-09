<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * UI-configurable settings with code defaults. Values are cached in the shared cache store
 * so every node behind the load balancer sees a change immediately after it is saved.
 */
class Settings
{
    public const CACHE_KEY = 'oms.settings.v1';

    public const DEFAULTS = [
        // FIFO assignment engine (spec §27.8)
        'fifo.enabled' => true,
        'fifo.assignment_mode' => 'auto',          // auto | manual
        'fifo.priority_aware' => true,
        'fifo.max_active_per_user' => 5,
        'fifo.ack_timeout_minutes' => 15,
        'fifo.requeue_on_rejection' => true,
        'fifo.requeue_on_timeout' => true,
        'fifo.exclude_on_leave' => true,
        'fifo.respect_shift' => true,
        'fifo.require_availability' => true,
        'fifo.skill_based' => false,
        'fifo.olt_coverage' => false,              // restrict technicians to OLTs their teams cover
        'fifo.manual_override' => true,
        'fifo.critical_bypass' => true,            // Critical tickets ignore the workload cap
        'fifo.requeue_on_unavailable' => 'unaccepted', // none | unaccepted | all
        'fifo.escalate_after_attempts' => 3,
        'fifo.enforce_assignee' => true,           // only the assignee (or an override holder) works the step

        // SLA
        'sla.pause_enabled' => true,
        'sla.default_resolution_minutes' => 240,
        'sla.default_pre_breach_minutes' => 30,

        // SLA per desk (Configuration → FIFO & SLA Settings): how long a ticket may wait at a desk, and
        // how soon an outage must be with a field technician. Defaults per desk: DeskSla::DEFAULT_MINUTES.
        'desk_sla.enabled' => true,
        'desk_sla.minutes' => [],                // desk (queue type) => minutes; 0 = no target
        'desk_sla.warn_percent' => 75,           // share of the target used before the warning goes out
        'desk_sla.field_minutes' => 30,          // outage reported → dispatched to a technician (0 = off)
        'desk_sla.email_breaches' => true,       // breaches also by e-mail (warnings are in-app)
        'desk_sla.stakeholder_roles' => [
            'NOC Manager', 'NOC Team Leader', 'CX Manager', 'CX Team Leader', 'Dispatch Manager', 'Infrastructure Manager',
            '4G NOC Manager', 'Ticketing Manager', 'Billing Manager',
        ],
        'desk_sla.stakeholder_emails' => [],

        // Field photos: mandatory, or offered but optional (Configuration → FIFO & SLA Settings)
        'photos.outage_required' => true,        // Emergency / Regular outage: the three site photos before "Report restored"
        'photos.maintenance_required' => true,   // preventive / corrective: "before" photos to raise it, "after" photos to complete it

        // Notifications
        'notifications.credit_due_on_breach' => true,

        // Alert mailing list (Configuration → Alert E-mails)
        'alerts.emails' => [],
        'alerts.events' => [
            'outage_created', 'noc_escalation', 'noc_confirmed', 'noc_rejected', 'cx_follow_up', 'site_visit_raised',
            'infrastructure_escalation', 'dispatched', 'support_requested', 'support_completed', 'it_escalation',
            'returned_to_it', 'update_posted', 'restore_reported', 'restore_confirmed', 'resolved', 'sla_breach',
        ],
        'alerts.email_assignee' => true,   // e-mail the person a ticket is assigned to

        // SMS alerts (same events by default; phone numbers normalised to E.164)
        'alerts.phones' => [],
        'alerts.sms_events' => [
            'outage_created', 'noc_escalation', 'noc_confirmed', 'noc_rejected', 'cx_follow_up', 'site_visit_raised',
            'infrastructure_escalation', 'dispatched', 'support_requested', 'support_completed', 'it_escalation',
            'returned_to_it', 'update_posted', 'restore_reported', 'restore_confirmed', 'resolved', 'sla_breach',
        ],
        'alerts.sms_assignee' => true,     // SMS the person a ticket is assigned to (if they have a phone)

        // Planned maintenance (Configuration → Maintenance Settings)
        'maintenance.reminder_offsets' => [1440, 720, 360, 180, 60, 30, 15, 5], // minutes before the start
        'maintenance.stakeholder_emails' => [],
        'maintenance.stakeholder_roles' => [
            'NOC Manager', 'NOC Team Leader', 'Infrastructure Manager', 'Infrastructure Team Leader',
            'Infrastructure Dispatcher', 'CX Manager', 'CX Team Leader',
        ],
        // Default CRF signatories (NOC can change them per CRF): role label + a user or an e-mail.
        'maintenance.default_signatories' => [
            ['role' => 'NOC', 'user_id' => null, 'email' => null],
            ['role' => 'CE Manager', 'user_id' => null, 'email' => null],
            ['role' => 'Department Head', 'user_id' => null, 'email' => null],
            ['role' => 'Chief Technology Officer (CTO)', 'user_id' => null, 'email' => null],
        ],
        'maintenance.reapprove_on_reschedule' => false, // re-collect signatures when the window moves
        'maintenance.sign_link_days' => 14,

        // 4G NOC (4G NOC → 4G Settings). 4G tickets use their own alert lists, not the FTTH ones above.
        'mobility.noc_contact' => '',                 // shown to vendors in the notification e-mail
        'mobility.vendor_silent_minutes' => 60,       // no vendor update for this long → remind the NOC (0 = off)
        'mobility.vendor_escalation_minutes' => 120,  // still nothing → management escalation (0 = off)
        'mobility.vendor_reminder_mail' => true,      // also e-mail the vendor a reminder at the escalation
        'mobility.escalation_roles' => ['4G NOC Manager'],
        'mobility.escalation_emails' => [],
        'mobility.alert_emails' => [],
        'mobility.alert_events' => self::MOBILITY_ALERT_EVENTS,
        'mobility.alert_phones' => [],
        'mobility.alert_sms_events' => [
            'outage_created', 'mobility_resolved_pending', 'resolved', 'mobility_reopened', 'mobility_escalated',
            'mobility_vendor_escalation', 'sla_breach',
        ],

        // Customer tickets and site visits (Customer Care → Ticket Settings). Their own alert lists too.
        'ticketing.sla_clock' => 'business',           // business | wall
        'ticketing.day_start' => '07:00',              // SLA counts only between these times…
        'ticketing.day_end' => '18:00',                // …a ticket raised after the end starts counting next working day
        'ticketing.working_days' => [1, 2, 3, 4, 5, 6, 7], // ISO days (1 = Monday … 7 = Sunday)
        'ticketing.sla_minutes' => 180,                // default target when the subcategory / visit type has none
        'ticketing.at_risk_percent' => 67,             // share of the SLA used before a ticket shows "at risk"
        'ticketing.dispatch_target_minutes' => 60,     // reported → assigned
        'ticketing.escalation_target_minutes' => 540,  // escalated → returned
        'ticketing.sla_tiers' => [3, 6, 9, 12, 15, 18, 21, 24, 48, 72], // TAT brackets in hours (then "above")
        'ticketing.followup_enabled' => true,
        'ticketing.followup_minutes' => 540,           // pending this long (SLA clock) → follow-up call + ready for credit
        'ticketing.credit_notify' => true,
        'ticketing.credit_roles' => ['CX Manager', 'Billing Manager'],
        'ticketing.credit_emails' => [],
        'ticketing.confirm_closure' => true,           // site visits: CX Field Support confirms with the customer before closing
        'ticketing.require_evidence' => true,          // FAT number + geolocation and speed-test photos to complete a visit
        'ticketing.max_daily_visits' => 0,             // per technician per day (0 = no limit)
        'ticketing.company_name' => 'Savanna Fibre',
        'ticketing.sms_customer' => true,
        'ticketing.sms_assigned' => 'Dear customer, your ticket {{ticket}} has been assigned to {{technician}} (Tel {{technician_phone}}), who will visit you {{visit_day}}. {{company}}',
        'ticketing.sms_rescheduled' => 'Dear customer, the visit for your ticket {{ticket}} has been rescheduled to {{visit_time}} as requested. {{company}}',
        'ticketing.sms_closed' => '',                  // empty = no SMS on closing
        'ticketing.alert_emails' => [],
        'ticketing.alert_events' => ['ticket_follow_up_due', 'ticket_credit_ready', 'sla_breach'],
        'ticketing.alert_phones' => [],
        'ticketing.alert_sms_events' => [],
    ];

    public const MOBILITY_ALERT_EVENTS = [
        'outage_created', 'assigned', 'update_posted', 'mobility_vendor_notified', 'mobility_vendor_mail_failed',
        'mobility_vendor_update', 'mobility_vendor_silent', 'mobility_vendor_escalation', 'mobility_resolved_pending',
        'mobility_verification_failed', 'resolved', 'mobility_reopened', 'mobility_cancelled', 'mobility_escalated',
        'sla_pre_breach', 'sla_breach',
    ];

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : ($default ?? Arr::get(self::DEFAULTS, $key));
    }

    public function bool(string $key): bool
    {
        return filter_var($this->get($key), FILTER_VALIDATE_BOOL);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $stored = Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                return Setting::query()->pluck('value', 'key')->all();
            } catch (\Throwable) {
                // Table not migrated yet (fresh install / tests before migrate).
                return [];
            }
        });

        return $this->loaded = array_merge(self::DEFAULTS, $stored);
    }

    /** @param array<string, mixed> $values */
    public function set(array $values, ?int $userId = null): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }
}
