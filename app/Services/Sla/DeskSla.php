<?php

namespace App\Services\Sla;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\QueueType;
use App\Models\MajorOutageType;
use App\Models\Outage;
use App\Models\OutageQueueEntry;
use App\Models\SlaEvent;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Tickets\BusinessClock;
use App\Support\AuditContext;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * SLA per desk (the escalation points of a ticket), next to the ticket's own SLA:
 *
 *  - DESK TARGET  how long a ticket may wait at a desk before that desk acts on it — confirms,
 *    rejects, dispatches, returns, escalates or closes. NOC 20 minutes, CX Tier II 15, the CX
 *    frontline (Tier I / CX agents) 5, by default; every desk can be given a target under
 *    Configuration → FIFO & SLA Settings. The target that applied on arrival stays with that stay.
 *  - TIME TO FIELD  an outage worked by a field team is dispatched to a technician within 30
 *    minutes of being reported.
 *
 * Both warn the people concerned before the time is up and again when it is, once each, and the
 * breaches are listed per desk under Reports. Customer tickets and site visits count their desk
 * time on the ticket SLA clock (working hours); outages and 4G tickets count every minute.
 */
class DeskSla
{
    /** Minutes a ticket may wait at each desk (0 / absent = no target). */
    public const DEFAULT_MINUTES = [
        'noc_review' => 20,
        'noc_restore' => 20,
        'ticket_noc' => 20,
        'mobility_noc' => 20,
        'cx_tier2' => 15,
        'cx_follow_up' => 15,
        'cx_callback' => 5,
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly BusinessClock $clock,
        private readonly NotificationDispatcher $notifier,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->bool('desk_sla.enabled');
    }

    /** @return array<string, int> every desk => minutes (0 = no target) */
    public function targets(): array
    {
        $stored = (array) $this->settings->get('desk_sla.minutes');

        return collect(QueueType::cases())->mapWithKeys(fn (QueueType $desk) => [
            $desk->value => max(0, (int) ($stored[$desk->value] ?? self::DEFAULT_MINUTES[$desk->value] ?? 0)),
        ])->all();
    }

    public function minutesFor(QueueType $desk): int
    {
        return $this->targets()[$desk->value] ?? 0;
    }

    public function fieldMinutes(): int
    {
        return max(0, $this->settings->int('desk_sla.field_minutes'));
    }

    /** The target of a desk as the ticket arrives there; it is not changed afterwards. */
    public function stamp(OutageQueueEntry $entry, Outage $outage, ?int $minutes = null): void
    {
        // A step of a custom workflow brings its own target; a desk takes the configured one.
        $minutes = $this->enabled() ? ($minutes ?? $this->minutesFor($entry->queue_type)) : 0;

        if ($minutes <= 0) {
            return;
        }

        // A visit booked for a later day is not waiting at the desk until that day.
        $start = $entry->eligible_from && $entry->eligible_from->greaterThan($entry->queued_at) ? $entry->eligible_from : $entry->queued_at;
        $warn = $this->warnMinutes($minutes);

        $entry->forceFill([
            'sla_minutes' => $minutes,
            'sla_due_at' => $this->after($outage, $start, $minutes),
            'sla_warn_at' => $warn ? $this->after($outage, $start, $warn) : null,
        ])->save();
    }

    /** An outage a field team works: when it should be with a technician. */
    public function stampField(Outage $outage): void
    {
        $minutes = $this->enabled() ? $this->fieldMinutes() : 0;

        if ($minutes <= 0 || $outage->workflow !== null || $outage->maintenance_kind !== null || $outage->dispatched_at
            || ! MajorOutageType::flags($outage->major_outage_type_id)['requires_infrastructure']) {
            return;
        }

        $start = CarbonImmutable::instance($outage->created_at ?? now());
        $warn = $this->warnMinutes($minutes);

        $outage->forceFill([
            'field_due_at' => $start->addMinutes($minutes),
            'field_warn_at' => $warn ? $start->addMinutes($warn) : null,
        ])->save();
    }

    /** Not an outage after all (NOC rejected it): nobody is going to the field. */
    public function clearField(Outage $outage): void
    {
        if ($outage->field_due_at && ! $outage->field_breached_at) {
            $outage->forceFill(['field_due_at' => null, 'field_warn_at' => null])->save();
        }
    }

    /**
     * Run every minute by the scheduler (oms:sla-check): each warning and each breach is told once.
     *
     * @return array{warned: int, breached: int, field_warned: int, field_breached: int}
     */
    public function check(?CarbonInterface $now = null): array
    {
        $result = ['warned' => 0, 'breached' => 0, 'field_warned' => 0, 'field_breached' => 0];

        if (! $this->enabled()) {
            return $result;
        }

        $now = CarbonImmutable::instance($now ?? now());

        OutageQueueEntry::query()
            ->open()
            ->whereNotNull('sla_due_at')
            ->whereNull('sla_breached_at')
            ->where(fn ($q) => $q->where('sla_due_at', '<=', $now)
                ->orWhere(fn ($warn) => $warn->whereNull('sla_warned_at')->whereNotNull('sla_warn_at')->where('sla_warn_at', '<=', $now)))
            ->orderBy('sla_due_at')
            ->limit(500)
            ->pluck('id')
            ->each(function ($id) use ($now, &$result): void {
                $outcome = AuditContext::as('system:desk-sla', fn () => DB::transaction(fn () => $this->evaluateEntry((int) $id, $now)));

                if ($outcome) {
                    $result[$outcome]++;
                }
            });

        Outage::query()
            ->active()
            ->whereNotNull('field_due_at')
            ->whereNull('dispatched_at')
            ->whereNull('field_breached_at')
            ->where(fn ($q) => $q->where('field_due_at', '<=', $now)
                ->orWhere(fn ($warn) => $warn->whereNull('field_warned_at')->whereNotNull('field_warn_at')->where('field_warn_at', '<=', $now)))
            ->orderBy('field_due_at')
            ->limit(500)
            ->pluck('id')
            ->each(function ($id) use ($now, &$result): void {
                $outcome = AuditContext::as('system:desk-sla', fn () => DB::transaction(fn () => $this->evaluateField((int) $id, $now)));

                if ($outcome) {
                    $result['field_'.$outcome]++;
                }
            });

        return $result;
    }

    /** "12 min left" / "over by 7 min" for a stay that has a target, null without one. */
    public static function describe(?CarbonInterface $due, ?CarbonInterface $at = null): ?string
    {
        if (! $due) {
            return null;
        }

        $seconds = ($at ?? now())->getTimestamp() - $due->getTimestamp();
        $minutes = (int) ceil(abs($seconds) / 60);
        $text = $minutes >= 120 ? round($minutes / 60, 1).' h' : $minutes.' min';

        return $seconds >= 0 ? "over by {$text}" : "{$text} left";
    }

    // ------------------------------------------------------------------ internals

    private function evaluateEntry(int $id, CarbonImmutable $now): ?string
    {
        $entry = OutageQueueEntry::query()->lockForUpdate()->with(['outage.status', 'activeAssignment.user:id,name'])->find($id);
        $outage = $entry?->outage;

        if (! $entry || ! $outage || $outage->is_closed || $entry->completed_at || $entry->sla_breached_at || ! $entry->sla_due_at) {
            return null;
        }

        $breached = $now->greaterThanOrEqualTo($entry->sla_due_at);

        if (! $breached && ($entry->sla_warned_at || ! $entry->sla_warn_at || $now->lessThan($entry->sla_warn_at))) {
            return null;
        }

        $entry->forceFill($breached ? ['sla_breached_at' => $now, 'sla_warned_at' => $entry->sla_warned_at ?? $now] : ['sla_warned_at' => $now])->save();

        $desk = $entry->queue_type;
        $deskName = $entry->label();
        $holder = $entry->activeAssignment?->user;
        $with = $holder ? "with {$holder->name}" : 'not yet picked up by anyone';
        $reason = $breached
            ? "Waiting at {$deskName} past its target of {$entry->sla_minutes} min ({$with})."
            : "At {$deskName} — ".self::describe($entry->sla_due_at, $now)." of its {$entry->sla_minutes} min target ({$with}).";

        $this->record($outage, $breached ? 'desk_breached' : 'desk_pre_breach', [
            'desk' => $desk->value, 'step' => $entry->step_name, 'target_minutes' => $entry->sla_minutes, 'held_by' => $holder?->name,
        ], $now);

        $this->tell($breached ? NotificationEvent::DeskSlaBreach : NotificationEvent::DeskSlaPreBreach, $outage, $breached, [
            'assignee_id' => $holder?->id, 'queue' => $deskName, 'reason' => $reason, 'by' => 'System (SLA per desk)',
        ]);

        return $breached ? 'breached' : 'warned';
    }

    private function evaluateField(int $id, CarbonImmutable $now): ?string
    {
        $outage = Outage::query()->lockForUpdate()->with('status')->find($id);

        if (! $outage || $outage->is_closed || $outage->dispatched_at || $outage->field_breached_at || ! $outage->field_due_at) {
            return null;
        }

        $breached = $now->greaterThanOrEqualTo($outage->field_due_at);

        if (! $breached && ($outage->field_warned_at || ! $outage->field_warn_at || $now->lessThan($outage->field_warn_at))) {
            return null;
        }

        $outage->forceFill($breached ? ['field_breached_at' => $now, 'field_warned_at' => $outage->field_warned_at ?? $now] : ['field_warned_at' => $now])->save();

        $minutes = (int) round($outage->created_at->diffInMinutes($outage->field_due_at));
        $where = $outage->current_queue?->getLabel() ?? $outage->status?->name ?? 'no desk';
        $reason = $breached
            ? "Not dispatched to a field technician within {$minutes} min of being reported. It is at: {$where}."
            : 'Not yet dispatched to a field technician — '.self::describe($outage->field_due_at, $now)." of the {$minutes} min target. It is at: {$where}.";

        $this->record($outage, $breached ? 'field_breached' : 'field_pre_breach', [
            'desk' => $outage->current_queue?->value, 'target_minutes' => $minutes,
        ], $now);

        $this->tell($breached ? NotificationEvent::FieldSlaBreach : NotificationEvent::FieldSlaPreBreach, $outage, true, [
            'queue' => $outage->current_queue?->getLabel(), 'reason' => $reason, 'by' => 'System (SLA per desk)',
        ]);

        return $breached ? 'breached' : 'warned';
    }

    /** The person holding it, their team leaders and the stakeholders; then the notification rules and alert lists. */
    private function tell(NotificationEvent $event, Outage $outage, bool $mail, array $context): void
    {
        $channels = array_values(array_filter([
            NotificationChannel::InApp->value,
            $mail && $this->settings->bool('desk_sla.email_breaches') ? NotificationChannel::Mail->value : null,
        ]));

        $this->notifier->notifyTargets($event, $outage, [
            'user_ids' => $this->stakeholderIds($outage),
            'emails' => $mail ? (array) $this->settings->get('desk_sla.stakeholder_emails') : [],
            'assignee' => true,
            'team_leaders' => true,
        ], $channels, array_filter($context, fn ($value) => $value !== null));

        $this->notifier->dispatch($event, $outage, array_filter($context, fn ($value) => $value !== null));
    }

    /**
     * People in the stakeholder roles who may see this kind of ticket: a CX manager is not told
     * about a 4G desk, nor a 4G NOC manager about a billing desk.
     *
     * @return list<int>
     */
    private function stakeholderIds(Outage $outage): array
    {
        $roles = array_values(array_filter((array) $this->settings->get('desk_sla.stakeholder_roles')));

        if ($roles === []) {
            return [];
        }

        $permission = match (true) {
            $outage->isTicket() => 'ticket.view',
            $outage->isCustomFlow() => 'custom.view',
            $outage->isMobility() => 'mobility.view',
            default => 'outage.view',
        };

        return User::query()->active()->role($roles)->get()->filter(fn (User $user) => $user->can($permission))->pluck('id')->all();
    }

    private function record(Outage $outage, string $event, array $meta, CarbonImmutable $now): void
    {
        SlaEvent::query()->create([
            'outage_id' => $outage->id,
            'sla_policy_id' => $outage->sla_policy_id,
            'event' => $event,
            'trigger' => AuditContext::trigger(),
            'meta' => array_filter($meta, fn ($value) => $value !== null),
            'occurred_at' => $now,
        ]);
    }

    /** Minutes into the target at which the warning goes out (none when the target is too short to warn). */
    private function warnMinutes(int $minutes): int
    {
        $percent = min(99, max(0, $this->settings->int('desk_sla.warn_percent')));
        $warn = (int) floor($minutes * $percent / 100);

        return $percent > 0 && $warn > 0 && $warn < $minutes ? $warn : 0;
    }

    private function after(Outage $outage, CarbonInterface $start, int $minutes): CarbonImmutable
    {
        return $outage->isTicket() ? $this->clock->add($start, $minutes) : CarbonImmutable::instance($start)->addMinutes($minutes);
    }
}
