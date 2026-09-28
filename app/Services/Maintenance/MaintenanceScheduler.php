<?php

namespace App\Services\Maintenance;

use App\Enums\NotificationEvent;
use App\Models\ChangeRequest;
use App\Models\MaintenanceMailSend;
use App\Models\MaintenanceTemplate;
use App\Models\OutageUpdate;
use App\Support\AuditContext;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Timed planned-maintenance notifications (run every minute by `oms:maintenance-tick`):
 *  - reminders at each configured offset before the start (24h, 12h, 6h, 3h, 1h, 30m, 15m, 5m by default);
 *  - "before_start" templates at their own offset;
 *  - the "maintenance started" alert when the window opens.
 *
 * Each send is claimed once per window start (unique row), so rescheduling produces a fresh set and
 * a busy or restarted scheduler never sends twice. A reminder whose moment passed more than the grace
 * period ago (e.g. the CRF was approved 3 hours before the start) is skipped rather than sent late.
 */
class MaintenanceScheduler
{
    public const GRACE_MINUTES = 10;

    public function __construct(
        private readonly MaintenanceNotifier $notifier,
        private readonly Settings $settings,
    ) {}

    /** @return int notifications sent */
    public function tick(?Carbon $now = null): int
    {
        $now ??= now();
        $offsets = $this->reminderOffsets();
        $beforeStart = MaintenanceTemplate::query()->active()->forTrigger('before_start')->whereNotNull('offset_minutes')->get();
        $horizon = max([0, ...$offsets, ...$beforeStart->pluck('offset_minutes')->all()]);

        $changeRequests = ChangeRequest::query()
            ->where('status', ChangeRequest::APPROVED)
            ->whereHas('outage', fn ($q) => $q->where('is_closed', false))
            ->whereBetween('scheduled_start', [$now->copy()->subHours(2), $now->copy()->addMinutes($horizon + 1)])
            ->with(['outage', 'signatures.user'])
            ->get();

        $sent = 0;

        foreach ($changeRequests as $changeRequest) {
            AuditContext::as('system:maintenance-scheduler', function () use ($changeRequest, $offsets, $beforeStart, $now, &$sent): void {
                $start = $changeRequest->scheduled_start;

                foreach ($offsets as $offset) {
                    if ($this->due($changeRequest, "reminder:{$offset}", $start->copy()->subMinutes($offset), $now)) {
                        $this->notifier->announce(NotificationEvent::MaintenanceReminder, $changeRequest, 'reminder', [
                            'time_left' => $this->notifier->timeLeft($changeRequest),
                        ]);
                        $sent++;
                    }
                }

                foreach ($beforeStart as $template) {
                    if ($this->due($changeRequest, "template:{$template->id}", $start->copy()->subMinutes($template->offset_minutes), $now)) {
                        $sent += $this->notifier->sendTemplates('before_start', $changeRequest, [], $template) > 0 ? 1 : 0;
                    }
                }

                if ($changeRequest->started_notified_at === null && $now->greaterThanOrEqualTo($start) && $this->claim($changeRequest, 'started', $now)) {
                    $changeRequest->forceFill(['started_notified_at' => $now])->save();
                    OutageUpdate::query()->create([
                        'outage_id' => $changeRequest->outage_id,
                        'type' => 'system',
                        'body' => 'Planned maintenance window started ('.$this->notifier->window($changeRequest).').',
                        'is_system' => true,
                    ]);
                    $this->notifier->announce(NotificationEvent::MaintenanceStarted, $changeRequest, 'started');
                    $sent++;
                }
            });
        }

        return $sent;
    }

    /** @return list<int> minutes before the start, largest first */
    public function reminderOffsets(): array
    {
        $offsets = array_map('intval', (array) $this->settings->get('maintenance.reminder_offsets'));
        $offsets = array_values(array_unique(array_filter($offsets, fn (int $m) => $m > 0)));
        rsort($offsets);

        return $offsets;
    }

    /** The moment has come and nobody handled it yet: claim it, then say whether it is still worth sending. */
    private function due(ChangeRequest $changeRequest, string $slot, Carbon $moment, Carbon $now): bool
    {
        if ($now->lessThan($moment) || $now->greaterThanOrEqualTo($changeRequest->scheduled_start)) {
            return false;
        }

        $late = $moment->diffInMinutes($now) > self::GRACE_MINUTES;

        return $this->claim($changeRequest, $slot, $now, $late ? 'skipped' : 'sent') && ! $late;
    }

    private function claim(ChangeRequest $changeRequest, string $slot, Carbon $now, string $status = 'sent'): bool
    {
        if (MaintenanceMailSend::query()->where('change_request_id', $changeRequest->id)->where('slot', $slot)
            ->where('window_start', $changeRequest->scheduled_start)->exists()) {
            return false;
        }

        try {
            MaintenanceMailSend::query()->create([
                'change_request_id' => $changeRequest->id,
                'slot' => $slot,
                'window_start' => $changeRequest->scheduled_start,
                'status' => $status,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false; // another node claimed it
        }
    }
}
