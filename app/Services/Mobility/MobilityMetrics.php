<?php

namespace App\Services\Mobility;

use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Models\Outage;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\User;
use App\Models\Vendor;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Figures for the 4G NOC dashboard, the hourly 4G e-mail and the 4G reports. Durations are
 * computed in PHP so the numbers are identical on MySQL and PostgreSQL.
 *
 *   Resolution time   ticket created → closed by the NOC
 *   Restoration time  outage start → service restored (engineer / vendor reports it fixed)
 *   MTTR              outage start → outage end, averaged over the tickets closed in the period
 */
class MobilityMetrics
{
    /** @return array<string, mixed> */
    public function overview(CarbonInterface $from, CarbonInterface $to): array
    {
        $open = Outage::query()->mobility()->active()->with('priority:id,code')->get(['id', 'fault_domain', 'workflow', 'status_id', 'priority_id', 'sla_state', 'sla_due_at', 'sla_pre_breach_at', 'sla_paused_at', 'is_closed', 'sites_down', 'cells_down']);
        $pending = $this->statusIds(StatusCode::MobilityResolvedPendingNoc, StatusCode::MobilityVendorResolvedPendingNoc);
        $new = $this->statusIds(StatusCode::MobilityNew);
        $closed = $this->closedIn($from, $to);
        $sla = $open->countBy(fn (Outage $outage) => $outage->currentSlaState()?->value);
        $severity = $open->countBy(fn (Outage $outage) => $outage->priority?->code);
        $met = $closed->where('sla_state', SlaState::Met)->count();
        $missed = $closed->where('sla_state', SlaState::Missed)->count();

        return [
            'total' => Outage::query()->mobility()->whereBetween('start_time', [$from, $to])->count(),
            'open' => $open->count(),
            'active' => $open->reject(fn (Outage $outage) => in_array($outage->status_id, [...$pending, ...$new], true))->count(),
            'pending_noc' => $open->whereIn('status_id', $pending)->count(),
            'closed' => $closed->count(),
            'enodeb' => $open->where('fault_domain', OutageType::DOMAIN_ENODEB)->count(),
            'transmission' => $open->where('fault_domain', OutageType::DOMAIN_TRANSMISSION)->count(),
            'power' => $open->where('fault_domain', OutageType::DOMAIN_POWER)->count(),
            'with_vendor' => $open->where('workflow', OutageType::WORKFLOW_MOBILITY_VENDOR)->count(),
            'critical' => (int) ($severity[Priority::MOBILITY_CRITICAL] ?? 0),
            'major' => (int) ($severity[Priority::MOBILITY_MAJOR] ?? 0),
            'minor' => (int) ($severity[Priority::MOBILITY_MINOR] ?? 0),
            'sla_breached' => (int) ($sla[SlaState::Breached->value] ?? 0),
            'sla_at_risk' => (int) ($sla[SlaState::AtRisk->value] ?? 0),
            'sla_compliance' => $met + $missed > 0 ? round($met / ($met + $missed) * 100, 1) : null,
            'sites_down' => (int) $open->sum('sites_down'),
            'cells_down' => (int) $open->sum('cells_down'),
            'avg_resolution' => $this->average($closed, fn (Outage $o) => $this->minutes($o->created_at, $o->resolved_at)),
            'avg_restoration' => $this->average($closed, fn (Outage $o) => $this->minutes($o->start_time, $o->restored_at)),
            'mttr' => $this->average($closed, fn (Outage $o) => $this->minutes($o->start_time, $o->end_time)),
        ];
    }

    /**
     * Per vendor: open tickets, tickets in the period, response and resolution time, SLA compliance.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function vendors(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $tickets = Outage::query()->mobility()->whereNotNull('vendor_id')
            ->where(fn (Builder $q) => $q->where('is_closed', false)->orWhereBetween('start_time', [$from, $to]))
            ->get(['id', 'vendor_id', 'is_closed', 'start_time', 'created_at', 'vendor_notified_at', 'vendor_acknowledged_at', 'vendor_resolved_at', 'restored_at', 'sla_state', 'vendor_escalation_level'])
            ->groupBy('vendor_id');

        return Vendor::query()->orderBy('name')->get(['id', 'name', 'code', 'is_active'])
            ->filter(fn (Vendor $vendor) => $vendor->is_active || $tickets->has($vendor->id))
            ->map(function (Vendor $vendor) use ($tickets, $from, $to): array {
                $all = $tickets->get($vendor->id, collect());
                $period = $all->filter(fn (Outage $o) => $o->start_time->between($from, $to));
                $done = $period->where('is_closed', true);
                $met = $done->where('sla_state', SlaState::Met)->count();
                $missed = $done->where('sla_state', SlaState::Missed)->count();

                return [
                    'vendor' => $vendor->name,
                    'open' => $all->where('is_closed', false)->count(),
                    'tickets' => $period->count(),
                    'closed' => $done->count(),
                    'escalated' => $period->where('vendor_escalation_level', '>', 0)->count(),
                    'avg_response' => $this->average($period, fn (Outage $o) => $this->minutes($o->vendor_notified_at ?? $o->created_at, $o->vendor_acknowledged_at)),
                    'avg_resolution' => $this->average($period, fn (Outage $o) => $this->minutes($o->start_time, $o->vendor_resolved_at ?? ($o->is_closed ? $o->restored_at : null))),
                    'sla_met' => $met,
                    'sla_missed' => $missed,
                    'sla_compliance' => $met + $missed > 0 ? round($met / ($met + $missed) * 100, 1) : null,
                ];
            })->values();
    }

    /**
     * Per engineer / technician: tickets assigned in the period, open, resolved, resolution time, SLA.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function engineers(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $tickets = Outage::query()->mobility()->where('workflow', OutageType::WORKFLOW_MOBILITY_INTERNAL)->whereNotNull('technician_id')
            ->where(fn (Builder $q) => $q->where('is_closed', false)->orWhereBetween('start_time', [$from, $to]))
            ->get(['id', 'technician_id', 'is_closed', 'start_time', 'created_at', 'acknowledged_at', 'work_started_at', 'restored_at', 'sla_state'])
            ->groupBy('technician_id');

        $names = User::query()->whereIn('id', $tickets->keys())->pluck('name', 'id');

        return $tickets->map(function (Collection $all, int $userId) use ($names, $from, $to): array {
            $period = $all->filter(fn (Outage $o) => $o->start_time->between($from, $to));
            $done = $period->where('is_closed', true);
            $met = $done->where('sla_state', SlaState::Met)->count();
            $missed = $done->where('sla_state', SlaState::Missed)->count();

            return [
                'engineer' => $names[$userId] ?? "#{$userId}",
                'assigned' => $period->count(),
                'open' => $all->where('is_closed', false)->count(),
                'resolved' => $period->whereNotNull('restored_at')->count(),
                'avg_response' => $this->average($period, fn (Outage $o) => $this->minutes($o->acknowledged_at, $o->work_started_at)),
                'avg_resolution' => $this->average($period, fn (Outage $o) => $this->minutes($o->start_time, $o->restored_at)),
                'sla_met' => $met,
                'sla_missed' => $missed,
                'sla_compliance' => $met + $missed > 0 ? round($met / ($met + $missed) * 100, 1) : null,
            ];
        })->sortByDesc('assigned')->values();
    }

    /**
     * MTTR per fault domain and severity for tickets closed in the period.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function mttr(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $closed = $this->closedIn($from, $to);
        $row = fn (string $group, Collection $tickets) => [
            'group' => $group,
            'tickets' => $tickets->count(),
            'mttr' => $this->average($tickets, fn (Outage $o) => $this->minutes($o->start_time, $o->end_time)),
            'avg_acknowledge' => $this->average($tickets, fn (Outage $o) => $this->minutes($o->created_at, $o->acknowledged_at)),
            'avg_restoration' => $this->average($tickets, fn (Outage $o) => $this->minutes($o->start_time, $o->restored_at)),
            'avg_resolution' => $this->average($tickets, fn (Outage $o) => $this->minutes($o->created_at, $o->resolved_at)),
            'longest' => $tickets->map(fn (Outage $o) => $this->minutes($o->start_time, $o->end_time))->filter(fn ($m) => $m !== null)->max(),
        ];

        return collect([$row('All 4G outages', $closed)])
            ->merge($closed->groupBy('fault_domain')->map(fn (Collection $tickets, $domain) => $row(OutageType::FAULT_DOMAINS[$domain] ?? 'Other', $tickets))->values())
            ->merge($closed->groupBy(fn (Outage $o) => $o->priority?->name ?? 'No severity')->map(fn (Collection $tickets, string $severity) => $row($severity, $tickets))->values());
    }

    /** Hours and minutes for people: "3h 20m". */
    public static function human(?int $minutes): string
    {
        return match (true) {
            $minutes === null => '—',
            $minutes >= 60 => intdiv($minutes, 60).'h '.($minutes % 60).'m',
            default => $minutes.'m',
        };
    }

    /** Tickets closed (not cancelled) in the period. */
    private function closedIn(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Outage::query()->mobility()->where('is_closed', true)->whereBetween('resolved_at', [$from, $to])
            ->with('priority:id,name,code')
            ->get(['id', 'fault_domain', 'priority_id', 'sla_state', 'start_time', 'created_at', 'acknowledged_at', 'restored_at', 'resolved_at', 'end_time']);
    }

    /** @return list<int> */
    private function statusIds(StatusCode ...$codes): array
    {
        return OutageStatus::query()->whereIn('code', array_map(fn (StatusCode $code) => $code->value, $codes))->pluck('id')->all();
    }

    private function minutes(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        return $from && $to ? max(0, (int) $from->diffInMinutes($to)) : null;
    }

    private function average(Collection $items, callable $value): ?int
    {
        $values = $items->map($value)->filter(fn ($minutes) => $minutes !== null);

        return $values->isEmpty() ? null : (int) round($values->avg());
    }
}
