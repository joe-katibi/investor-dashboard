<?php

namespace App\Services\Reports;

use App\Models\Outage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Shared report filters (spec §20): date/time, status, priority, outage type, major type,
 * vertical, OLT/FDT, service, team, technician and SLA state. Only portable SQL is used so
 * the same query runs on MySQL and PostgreSQL.
 */
class OutageQuery
{
    public const RELATIONS = [
        'majorOutageType:id,name,code', 'outageType:id,name,code,is_planned', 'vertical:id,name,code',
        'priority:id,name,code,severity', 'status:id,name,code', 'team:id,name', 'subTeam:id,name',
        'technician:id,name', 'currentAssignee:id,name', 'slaPolicy:id,name',
        'olts:id,name', 'fdts:id,name,olt_id', 'slots:id,name,fdt_id', 'ports:id,name,slot_id', 'services:id,name',
        'creditUpdatedBy:id,name', 'smsUpdatedBy:id,name',
    ];

    public static function make(array $filters = []): Builder
    {
        return self::apply(Outage::query()->with(self::RELATIONS), $filters);
    }

    public static function apply(Builder $query, array $filters): Builder
    {
        [$from, $to] = self::period($filters);

        $in = fn (string $key) => array_values(array_filter((array) ($filters[$key] ?? []), fn ($v) => $v !== null && $v !== ''));

        return $query
            ->when($from, fn (Builder $q) => $q->where('start_time', '>=', $from))
            ->when($to, fn (Builder $q) => $q->where('start_time', '<=', $to))
            ->when($in('status_ids'), fn (Builder $q, $v) => $q->whereIn('status_id', $v))
            ->when($in('priority_ids'), fn (Builder $q, $v) => $q->whereIn('priority_id', $v))
            ->when($in('outage_type_ids'), fn (Builder $q, $v) => $q->whereIn('outage_type_id', $v))
            ->when($in('major_type_ids'), fn (Builder $q, $v) => $q->whereIn('major_outage_type_id', $v))
            ->when($in('vertical_ids'), fn (Builder $q, $v) => $q->whereIn('vertical_id', $v))
            ->when($in('team_ids'), fn (Builder $q, $v) => $q->where(fn (Builder $t) => $t->whereIn('team_id', $v)->orWhereIn('sub_team_id', $v)))
            ->when($in('technician_ids'), fn (Builder $q, $v) => $q->whereIn('technician_id', $v))
            ->when($in('sla_states'), fn (Builder $q, $v) => $q->whereIn('sla_state', $v))
            ->when($in('olt_ids'), fn (Builder $q, $v) => $q->whereHas('olts', fn (Builder $o) => $o->whereIn('olts.id', $v)))
            ->when($in('fdt_ids'), fn (Builder $q, $v) => $q->whereHas('fdts', fn (Builder $o) => $o->whereIn('fdts.id', $v)))
            ->when($in('service_ids'), fn (Builder $q, $v) => $q->whereHas('services', fn (Builder $o) => $o->whereIn('services.id', $v)))
            ->when(($filters['state'] ?? null) === 'active', fn (Builder $q) => $q->where('is_closed', false))
            ->when(($filters['state'] ?? null) === 'closed', fn (Builder $q) => $q->where('is_closed', true));
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    public static function period(array $filters): array
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from']) : null;
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to']) : null;

        // A bare date for "to" means the whole day.
        if ($to && strlen((string) $filters['to']) <= 10) {
            $to = $to->endOfDay();
        }

        return [$from, $to];
    }
}
