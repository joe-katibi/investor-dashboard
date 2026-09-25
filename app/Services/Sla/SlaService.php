<?php

namespace App\Services\Sla;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Events\OutageUpdated;
use App\Models\Outage;
use App\Models\OutageStatus;
use App\Models\SlaEvent;
use App\Models\SlaPolicy;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\AuditContext;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * SLA engine (spec §11). Deadlines are calculated from the outage start time using the most
 * specific active policy; configured statuses pause/resume the clock.
 */
class SlaService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly NotificationDispatcher $notifier,
    ) {}

    public function resolvePolicy(Outage $outage): ?SlaPolicy
    {
        $serviceIds = $outage->services()->pluck('services.id')->all();

        return SlaPolicy::query()
            ->active()
            ->get()
            ->filter(function (SlaPolicy $policy) use ($outage, $serviceIds): bool {
                foreach (['major_outage_type_id', 'outage_type_id', 'vertical_id', 'priority_id'] as $column) {
                    if ($policy->{$column} !== null && (int) $policy->{$column} !== (int) $outage->{$column}) {
                        return false;
                    }
                }

                return $policy->service_id === null || in_array($policy->service_id, $serviceIds, true);
            })
            ->sort(fn (SlaPolicy $a, SlaPolicy $b) => [$b->specificity(), $a->resolution_minutes] <=> [$a->specificity(), $b->resolution_minutes])
            ->first();
    }

    /** Apply (or re-apply after a priority/type change) the policy and recompute deadlines. */
    public function apply(Outage $outage, string $event = 'started'): void
    {
        $policy = $this->resolvePolicy($outage);

        $resolution = $policy?->resolution_minutes ?? $this->settings->int('sla.default_resolution_minutes');
        $preBreach = $policy?->pre_breach_minutes ?? $this->settings->int('sla.default_pre_breach_minutes');

        $due = $outage->start_time->copy()->addMinutes($resolution)->addSeconds($outage->sla_paused_seconds);

        $outage->forceFill([
            'sla_policy_id' => $policy?->id,
            'sla_due_at' => $due,
            'sla_pre_breach_at' => $due->copy()->subMinutes(min($preBreach, $resolution)),
        ]);

        if (! $outage->is_closed) {
            $outage->sla_state = $this->stateAt($outage, now());
        }

        $outage->save();

        $this->event($outage, $event, ['policy' => $policy?->name ?? 'Default', 'resolution_minutes' => $resolution, 'due_at' => $due->toIso8601String()]);
    }

    public function stateAt(Outage $outage, CarbonInterface $moment): SlaState
    {
        if (! $outage->sla_due_at) {
            return SlaState::NotApplicable;
        }

        // While paused the clock is frozen at the pause moment.
        $effective = $outage->sla_paused_at ?? $moment;

        return match (true) {
            $effective->greaterThanOrEqualTo($outage->sla_due_at) => SlaState::Breached,
            $outage->sla_pre_breach_at && $effective->greaterThanOrEqualTo($outage->sla_pre_breach_at) => SlaState::AtRisk,
            default => SlaState::Compliant,
        };
    }

    /** Pause / resume based on the new status' "pauses SLA" flag. */
    public function onStatusChanged(Outage $outage, OutageStatus $status): void
    {
        if (! $this->settings->bool('sla.pause_enabled') || ($outage->slaPolicy && ! $outage->slaPolicy->allow_pause)) {
            return;
        }

        if ($status->pauses_sla && ! $outage->sla_paused_at) {
            $outage->forceFill(['sla_paused_at' => now()])->save();
            $this->event($outage, 'paused', ['status' => $status->name]);
        } elseif (! $status->pauses_sla && $outage->sla_paused_at) {
            $seconds = (int) $outage->sla_paused_at->diffInSeconds(now());

            $outage->forceFill([
                'sla_paused_seconds' => $outage->sla_paused_seconds + $seconds,
                'sla_due_at' => $outage->sla_due_at?->copy()->addSeconds($seconds),
                'sla_pre_breach_at' => $outage->sla_pre_breach_at?->copy()->addSeconds($seconds),
                'sla_paused_at' => null,
            ])->save();

            $this->event($outage, 'resumed', ['paused_seconds' => $seconds]);
        }
    }

    /** Final SLA outcome when the outage closes. */
    public function finalize(Outage $outage): void
    {
        $outage->loadMissing('status');

        if ($outage->status?->isCode(StatusCode::NocReject)) {
            $state = SlaState::NotApplicable;
        } else {
            $end = $outage->end_time ?? $outage->restored_at ?? now();
            $state = $outage->sla_due_at && $end->greaterThan($outage->sla_due_at) ? SlaState::Missed : SlaState::Met;
        }

        $outage->forceFill(['sla_state' => $state, 'sla_paused_at' => null])->save();
        $this->event($outage, $state->value);
    }

    /**
     * Periodic evaluation (scheduler, every minute): state transitions, pre-breach, breach,
     * credit-due and escalation notifications. Each notice is sent exactly once per outage.
     */
    public function check(): int
    {
        $changed = 0;
        $now = now();

        Outage::query()
            ->active()
            ->whereNotNull('sla_due_at')
            ->whereNull('sla_paused_at')
            ->with(['slaPolicy', 'priority', 'status'])
            ->chunkById(200, function ($outages) use ($now, &$changed): void {
                foreach ($outages as $outage) {
                    AuditContext::as('system:sla-check', function () use ($outage, $now, &$changed): void {
                        DB::transaction(function () use ($outage, $now, &$changed): void {
                            if ($this->evaluate($outage, $now)) {
                                $changed++;
                            }
                        });
                    });
                }
            });

        return $changed;
    }

    private function evaluate(Outage $outage, CarbonInterface $now): bool
    {
        $state = $this->stateAt($outage, $now);
        $dirty = false;
        $policy = $outage->slaPolicy;
        $targets = [
            'roles' => $policy?->notify_roles,
            'user_ids' => $policy?->notify_user_ids,
            'emails' => $policy?->notify_emails,
            'assignee' => true,
            'team_leaders' => true,
        ];
        $channels = [NotificationChannel::InApp->value, NotificationChannel::Mail->value];

        if ($state !== $outage->sla_state) {
            $outage->sla_state = $state;
            $dirty = true;
        }

        if ($state === SlaState::AtRisk && ! $outage->sla_pre_breach_notified_at) {
            $outage->sla_pre_breach_notified_at = $now;
            $dirty = true;
            $this->event($outage, 'pre_breach');
            $this->notifier->notifyTargets(NotificationEvent::SlaPreBreach, $outage, $targets, $channels);
            $this->notifier->dispatch(NotificationEvent::SlaPreBreach, $outage);
        }

        if ($state === SlaState::Breached && ! $outage->sla_breach_notified_at) {
            $outage->sla_breach_notified_at = $now;
            $outage->sla_pre_breach_notified_at ??= $now;
            $dirty = true;
            $this->event($outage, 'breached');
            $this->notifier->notifyTargets(NotificationEvent::SlaBreach, $outage, $targets, $channels);
            $this->notifier->dispatch(NotificationEvent::SlaBreach, $outage);

            if (($policy?->notify_credit ?? $this->settings->bool('notifications.credit_due_on_breach')) && ! $outage->credit_shared) {
                $this->notifier->notifyTargets(NotificationEvent::CreditDue, $outage, $targets, $channels);
                $this->notifier->dispatch(NotificationEvent::CreditDue, $outage);
            }
        }

        if ($policy?->escalation_minutes && ! $outage->sla_escalated_at
            && $now->greaterThanOrEqualTo($outage->start_time->copy()->addMinutes($policy->escalation_minutes)->addSeconds($outage->sla_paused_seconds))) {
            $outage->sla_escalated_at = $now;
            $dirty = true;
            $this->event($outage, 'escalated', ['roles' => $policy->escalation_roles]);
            $this->notifier->notifyTargets(NotificationEvent::SlaEscalation, $outage, ['roles' => $policy->escalation_roles, 'team_leaders' => true], $channels);
            $this->notifier->dispatch(NotificationEvent::SlaEscalation, $outage);
        }

        if ($dirty) {
            $outage->save();
            event(new OutageUpdated($outage->id, $outage->ticket_number, [], ['sla_state' => $state->value]));
        }

        return $dirty;
    }

    private function event(Outage $outage, string $event, array $meta = []): void
    {
        SlaEvent::query()->create([
            'outage_id' => $outage->id,
            'sla_policy_id' => $outage->sla_policy_id,
            'event' => $event,
            'trigger' => AuditContext::trigger(),
            'meta' => $meta ?: null,
            'occurred_at' => now(),
        ]);
    }
}
