<?php

namespace App\Services\Mobility;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\StatusCode;
use App\Events\OutageUpdated;
use App\Models\Outage;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\OutageUpdate;
use App\Models\VendorTemplate;
use App\Services\Maintenance\MaintenanceNotifier;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\AuditContext;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Escalation of silent vendors (4G NOC → 4G Settings), run every minute by the scheduler:
 *
 *   Ticket created → vendor notified → no vendor update within X minutes → the NOC is reminded to
 *   chase the vendor → still nothing after Y minutes → management is told and the vendor gets a
 *   reminder e-mail.
 *
 * The clock restarts with every vendor update the NOC records. When the vendor promised a "next
 * update" time, the first reminder waits for that time instead of X.
 */
class VendorEscalationMonitor
{
    public const NOC_REMINDER = 1;

    public const MANAGEMENT = 2;

    /** Vendor tickets in these statuses are not waiting on the vendor. */
    private const NOT_WAITING = [StatusCode::MobilityNew, StatusCode::MobilityMonitoring, StatusCode::MobilityVendorResolvedPendingNoc];

    public function __construct(
        private readonly Settings $settings,
        private readonly NotificationDispatcher $notifier,
        private readonly VendorNotifier $vendorMail,
    ) {}

    /** @return int number of escalations raised */
    public function tick(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $silent = $this->settings->int('mobility.vendor_silent_minutes');
        $escalation = $this->settings->int('mobility.vendor_escalation_minutes');
        $skip = collect(self::NOT_WAITING)->map(fn (StatusCode $code) => OutageStatus::query()->where('code', $code->value)->value('id'))->filter()->all();
        $raised = 0;

        Outage::query()
            ->active()
            ->where('workflow', OutageType::WORKFLOW_MOBILITY_VENDOR)
            ->whereNotNull('vendor_id')
            ->where('vendor_escalation_level', '<', self::MANAGEMENT)
            ->when($skip !== [], fn ($q) => $q->whereNotIn('status_id', $skip))
            ->with(['vendor', 'status', 'priority'])
            ->chunkById(200, function ($outages) use ($now, $silent, $escalation, &$raised): void {
                foreach ($outages as $outage) {
                    $level = $this->dueLevel($outage, $now, $silent, $escalation);

                    if ($level > $outage->vendor_escalation_level && $this->claim($outage, $level, $now)) {
                        AuditContext::as('system:vendor-escalation', fn () => DB::transaction(fn () => $this->raise($outage, $level, $now)));
                        $raised++;
                    }
                }
            });

        return $raised;
    }

    /** The escalation level the ticket should be at now (0 = none). */
    public function dueLevel(Outage $outage, CarbonInterface $now, int $silent, int $escalation): int
    {
        // Last sign of life from the vendor side.
        $base = collect([$outage->vendor_last_update_at, $outage->vendor_notified_at, $outage->reopened_at, $outage->created_at])->filter()->max();
        $promised = $outage->vendor_next_update_at;

        $first = $promised ?? ($silent > 0 ? $base->copy()->addMinutes($silent) : null);
        $second = $escalation > 0
            ? ($promised ? $promised->copy()->addMinutes(max(0, $escalation - $silent)) : $base->copy()->addMinutes($escalation))
            : null;

        return match (true) {
            $second !== null && $now->greaterThanOrEqualTo($second) => self::MANAGEMENT,
            $first !== null && $now->greaterThanOrEqualTo($first) => self::NOC_REMINDER,
            default => 0,
        };
    }

    /** Atomic claim, so two scheduler nodes never raise the same escalation twice. */
    private function claim(Outage $outage, int $level, CarbonInterface $now): bool
    {
        return Outage::query()->whereKey($outage->id)
            ->where('vendor_escalation_level', $outage->vendor_escalation_level)
            ->update(['vendor_escalation_level' => $level, 'vendor_escalated_at' => $now]) === 1;
    }

    private function raise(Outage $outage, int $level, CarbonInterface $now): void
    {
        $base = collect([$outage->vendor_last_update_at, $outage->vendor_notified_at, $outage->reopened_at, $outage->created_at])->filter()->max();
        $quiet = MaintenanceNotifier::duration(max(1, (int) $base->diffInMinutes($now)));
        $vendor = $outage->vendor?->name ?? 'the vendor';
        $management = $level === self::MANAGEMENT;

        $reason = $management
            ? "No update from {$vendor} for {$quiet}. Escalated to management."
            : ($outage->vendor_next_update_at
                ? "{$vendor} promised an update at {$outage->vendor_next_update_at->format('H:i')} and none has been recorded. Follow up and record the vendor update."
                : "No update from {$vendor} for {$quiet}. Follow up and record the vendor update.");

        OutageUpdate::query()->create([
            'outage_id' => $outage->id, 'type' => 'escalation', 'actor_type' => OutageUpdate::ACTOR_SYSTEM, 'body' => $reason, 'is_system' => true,
        ]);

        $event = $management ? NotificationEvent::MobilityVendorEscalation : NotificationEvent::MobilityVendorSilent;
        $context = ['reason' => $reason, 'by' => 'System'];

        if ($management) {
            $this->notifier->notifyTargets($event, $outage, [
                'roles' => array_values(array_filter((array) $this->settings->get('mobility.escalation_roles'))),
                'emails' => (array) $this->settings->get('mobility.escalation_emails'),
            ], [NotificationChannel::InApp->value, NotificationChannel::Mail->value], $context);

            if ($this->settings->bool('mobility.vendor_reminder_mail')) {
                $this->vendorMail->send(VendorTemplate::REMINDER, $outage, ['reason' => "No update received for {$quiet}."]);
            }
        }

        $this->notifier->dispatch($event, $outage, $context);
        event(new OutageUpdated($outage->id, $outage->ticket_number));
    }
}
