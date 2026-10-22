<?php

namespace App\Services\Performance;

use App\Enums\DataSource;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Models\OutageStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo outages and site visits for the periods the real sources do not cover, and a live trickle of
 * demo site visits. Every demo row has data_source = demo, a "DEMO-…" number, and is shown as demo data.
 *
 * A demo row is a copy of a randomly drawn REAL row (issue, OLT, crew, time of day, time to complete,
 * SLA result) moved to another day — so demo months look like the real months, no better and no worse.
 */
class DemoGenerator
{
    /** How far back the dashboard looks, and how far back gaps are filled. */
    public const MONTHS = 6;

    private const CHUNK = 500;

    public function __construct(private readonly HistoryImporter $importer) {}

    /**
     * Fills [today − 6 months, now] where a real source has no rows: site visits before the first and after
     * the last tracker ticket, outages after the last logged incident. Replaces earlier demo history.
     *
     * @return array{visits: int, outages: int, visit_gaps: list<string>, outage_gaps: list<string>}
     */
    public function backfill(): array
    {
        DB::table('outages')->where('data_source', DataSource::Demo->value)->delete();
        DB::table('demo_sequence')->delete();

        $from = now()->subMonthsNoOverflow(self::MONTHS)->startOfDay();
        $result = ['visits' => 0, 'outages' => 0, 'visit_gaps' => [], 'outage_gaps' => []];

        foreach ($this->gaps(DataSource::TicketTracker, $from) as [$start, $end]) {
            $result['visit_gaps'][] = $start->format('j M').' – '.$end->format('j M Y');
            $result['visits'] += $this->visits($start, $end, $this->perDay(DataSource::TicketTracker));
        }

        foreach ($this->gaps(DataSource::IncidentLog, $from) as [$start, $end]) {
            $result['outage_gaps'][] = $start->format('j M').' – '.$end->format('j M Y');
            $result['outages'] += $this->outages($start, $end, $this->perDay(DataSource::IncidentLog));
        }

        Cache::forever('performance.demo_tick', now()->getTimestamp());

        return $result;
    }

    /**
     * The live trickle: new demo site visits at the real average rate for the minutes since the last tick,
     * and open demo visits closed once their (sampled) completion time has come.
     *
     * @return array{created: int, closed: int}
     */
    public function tick(): array
    {
        $last = Carbon::createFromTimestamp((int) Cache::get('performance.demo_tick', now()->subMinute()->getTimestamp()), config('app.timezone'));
        $now = now();
        $minutes = min(max($last->diffInMinutes($now), 0), 180);
        Cache::forever('performance.demo_tick', $now->getTimestamp());

        $created = 0;
        $count = $this->poisson($this->perDay(DataSource::TicketTracker) / 1440 * $minutes);
        if ($count > 0) {
            $created = $this->insertVisits(array_map(fn () => $last->copy()->addSeconds(random_int(0, max(1, $last->diffInSeconds($now)))), range(1, $count)));
        }

        return ['created' => $created, 'closed' => $this->closeDue()];
    }

    /** Open demo visits whose completion time has passed are closed with their sampled result. */
    public function closeDue(): int
    {
        $closed = OutageStatus::forCode(StatusCode::VisitClosed)->id;
        $due = DB::table('outages')->where('data_source', DataSource::Demo->value)->where('is_closed', false)
            ->whereNotNull('demo_closes_at')->where('demo_closes_at', '<=', now())->get(['id', 'demo_closes_at', 'elapsed_hours', 'sla_due_at', 'start_time']);

        foreach ($due as $row) {
            $at = Carbon::parse($row->demo_closes_at);
            $met = $row->elapsed_hours !== null && $row->sla_due_at !== null
                && (float) $row->elapsed_hours <= Carbon::parse($row->start_time)->diffInMinutes(Carbon::parse($row->sla_due_at)) / 60;

            DB::table('outages')->where('id', $row->id)->update([
                'is_closed' => true, 'status_id' => $closed, 'end_time' => $at, 'resolved_at' => $at, 'demo_closes_at' => null,
                'sla_state' => $met ? SlaState::Met->value : SlaState::Missed->value, 'updated_at' => $at,
            ]);
            DB::table('tickets')->where('outage_id', $row->id)->update(['completed_at' => $at, 'confirmed_at' => $at, 'updated_at' => $at]);
        }

        return $due->count();
    }

    // ------------------------------------------------------------------ gaps and volumes

    /**
     * Stretches inside [$from, now] that a source does not cover: before its first row and after its last.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function gaps(DataSource $source, Carbon $from): array
    {
        [$first, $last] = $this->coverage($source);
        if (! $first) {
            return [[$from, now()]];
        }

        $gaps = [];
        if ($first->gt($from)) {
            $gaps[] = [$from->copy(), $first->copy()->subDay()->endOfDay()];
        }
        if ($last->lt(now()->startOfDay())) {
            $gaps[] = [$last->copy()->addDay()->startOfDay(), now()];
        }

        return $gaps;
    }

    /**
     * First and last day of a source's regular coverage. Stray single rows far from the rest (the tracker
     * has one ticket on 31 Aug, a week before the others) do not count as coverage.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function coverage(DataSource $source): array
    {
        $days = DB::table('outages')->where('data_source', $source->value)
            ->selectRaw('DATE(start_time) as day, COUNT(*) as n')->groupBy('day')->orderBy('day')->pluck('n', 'day');

        if ($days->isEmpty()) {
            return [null, null];
        }

        $floor = max(1, (int) floor($days->avg() * 0.1));
        $regular = $days->filter(fn ($n) => $n >= $floor)->keys();

        return [Carbon::parse($regular->first(), config('app.timezone')), Carbon::parse($regular->last(), config('app.timezone'))];
    }

    /** Average rows per day over the source's regular coverage. */
    public function perDay(DataSource $source): float
    {
        [$first, $last] = $this->coverage($source);
        if (! $first) {
            return 0;
        }

        $count = DB::table('outages')->where('data_source', $source->value)->whereBetween('start_time', [$first, $last->copy()->endOfDay()])->count();

        return $count / max(1, $first->diffInDays($last) + 1);
    }

    // ------------------------------------------------------------------ site visits

    private function visits(Carbon $start, Carbon $end, float $perDay): int
    {
        // A day at a time: a gap of several months is tens of thousands of visits.
        $count = 0;
        for ($day = $start->copy()->startOfDay(); $day->lte($end); $day->addDay()) {
            $count += $this->insertVisits(array_fill(0, $this->poisson($perDay), $day->copy()), keepTimeOfDay: true);
        }

        return $count;
    }

    /**
     * One demo visit per moment, each a copy of a random real visit. With $keepTimeOfDay the moment is a day
     * and the visit takes the real visit's time of day; otherwise the moment is the exact time raised.
     *
     * @param  list<Carbon>  $moments
     */
    private function insertVisits(array $moments, bool $keepTimeOfDay = false): int
    {
        $pool = $this->pool(DataSource::TicketTracker);
        if ($pool === [] || $moments === []) {
            return 0;
        }

        $open = OutageStatus::forCode(StatusCode::VisitOpen)->id;
        $closed = OutageStatus::forCode(StatusCode::VisitClosed)->id;
        $now = now();
        $outages = [];
        $tickets = [];
        $next = $this->reserve('V', count($moments));

        foreach ($moments as $moment) {
            $real = $pool[array_rand($pool)];
            $realStart = Carbon::parse($real->start_time);
            $start = $keepTimeOfDay ? $moment->copy()->setTimeFrom($realStart) : $moment;
            if ($start->gt($now)) {
                continue;
            }

            // Time to complete: the real visit's clock time (its SLA hours are kept as they were measured).
            $duration = $real->end_time ? $realStart->diffInSeconds(Carbon::parse($real->end_time)) : null;
            $end = $duration !== null ? $start->copy()->addSeconds((int) $duration) : null;
            $isClosed = $end !== null && $end->lte($now);
            $number = sprintf('DEMO-V-%06d', $next++);

            $outages[] = [
                'ticket_number' => $number,
                // The issue only — never the real customer's account number.
                'title' => Str::before($real->title, ' — ').' — DEMO',
                'workflow' => $real->workflow,
                'outage_type_id' => $real->outage_type_id,
                'major_outage_type_id' => $real->major_outage_type_id,
                'vertical_id' => $real->vertical_id,
                'status_id' => $isClosed ? $closed : $open,
                'is_closed' => $isClosed,
                'customers_affected' => 1,
                'location' => $real->location,
                'team_id' => $real->team_id,
                'start_time' => $start,
                'detected_at' => $start,
                'dispatched_at' => $real->dispatched_at ? $start->copy()->addSeconds(max(0, $realStart->diffInSeconds(Carbon::parse($real->dispatched_at), false))) : null,
                'end_time' => $isClosed ? $end : null,
                'resolved_at' => $isClosed ? $end : null,
                'sla_due_at' => $real->sla_due_at ? $start->copy()->addSeconds((int) $realStart->diffInSeconds(Carbon::parse($real->sla_due_at))) : null,
                'sla_state' => $isClosed ? $real->sla_state : ($real->sla_state === SlaState::NotApplicable->value ? $real->sla_state : SlaState::Compliant->value),
                'elapsed_hours' => $real->elapsed_hours,
                'demo_closes_at' => $isClosed ? null : $end,
                'data_source' => DataSource::Demo->value,
                'created_at' => $start,
                'updated_at' => $isClosed ? $end : $start,
            ];
            $tickets[$number] = [
                'account_number' => 'DEMO',
                'olt_id' => $real->olt_id,
                'category_id' => $real->category_id,
                'subcategory_id' => $real->subcategory_id,
                'visit_type_id' => $real->visit_type_id,
                'ticket_type' => $real->ticket_type,
                'first_assigned_at' => null,
                'assigned_at' => null,
                'completed_at' => $isClosed ? $end : null,
                'confirmed_at' => $isClosed ? $end : null,
            ];
        }

        $this->importer->insertVisits($outages, $tickets);

        return count($outages);
    }

    // ------------------------------------------------------------------ outages

    private function outages(Carbon $start, Carbon $end, float $perDay): int
    {
        $pool = $this->pool(DataSource::IncidentLog);
        if ($pool === []) {
            return 0;
        }

        $now = now();
        $rows = [];
        for ($day = $start->copy()->startOfDay(); $day->lte($end); $day->addDay()) {
            $n = $this->poisson($perDay);
            $next = $this->reserve('O', $n);
            for (; $n > 0; $n--) {
                $real = $pool[array_rand($pool)];
                $realStart = Carbon::parse($real->start_time);
                $begin = $day->copy()->setTimeFrom($realStart);
                $cleared = $real->end_time ? $begin->copy()->addSeconds($realStart->diffInSeconds(Carbon::parse($real->end_time))) : null;
                if ($begin->gt($now)) {
                    continue;
                }
                // Not yet cleared by now: shown as cleared now at the latest (demo outages are not live work).
                $cleared = $cleared && $cleared->gt($now) ? $now->copy() : $cleared;

                $rows[] = [
                    'ticket_number' => sprintf('DEMO-O-%06d', $next++),
                    'title' => $real->title,
                    'description' => $real->description,
                    'location' => $real->location,
                    'customers_affected' => $real->customers_affected,
                    'outage_type_id' => $real->outage_type_id,
                    'major_outage_type_id' => $real->major_outage_type_id,
                    'vertical_id' => $real->vertical_id,
                    'status_id' => $real->status_id,
                    'is_closed' => true,
                    'start_time' => $begin,
                    'detected_at' => $begin,
                    'end_time' => $cleared,
                    'restored_at' => $cleared,
                    'resolved_at' => $cleared,
                    'sla_due_at' => $begin->copy()->addHours(HistoryImporter::INCIDENT_SLA_HOURS),
                    'sla_state' => $real->sla_state,
                    'elapsed_hours' => $cleared ? round($begin->diffInMinutes($cleared) / 60, 2) : null,
                    'final_reason' => $real->final_reason,
                    'data_source' => DataSource::Demo->value,
                    'created_at' => $begin,
                    'updated_at' => $cleared ?? $begin,
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('outages')->insert($chunk);
        }

        return count($rows);
    }

    // ------------------------------------------------------------------ helpers

    /** @var array<string, list<object>> */
    private array $pools = [];

    /** @return list<object> the real rows demo rows are drawn from */
    private function pool(DataSource $source): array
    {
        $columns = ['title', 'workflow', 'outage_type_id', 'major_outage_type_id', 'vertical_id', 'status_id', 'location', 'team_id', 'customers_affected',
            'start_time', 'dispatched_at', 'end_time', 'sla_due_at', 'sla_state', 'elapsed_hours'];
        $columns = $source === DataSource::IncidentLog
            ? [...array_map(fn ($c) => "outages.{$c}", $columns), 'outages.description', 'outages.final_reason']
            : [...array_map(fn ($c) => "outages.{$c}", $columns), 'tickets.olt_id', 'tickets.category_id', 'tickets.subcategory_id', 'tickets.visit_type_id', 'tickets.ticket_type'];

        return $this->pools[$source->value] ??= DB::table('outages')
            ->leftJoin('tickets', 'tickets.outage_id', '=', 'outages.id')
            ->where('outages.data_source', $source->value)
            ->get($columns)
            ->all();
    }

    /** Reserves $count numbers of the DEMO-V-n / DEMO-O-n counter; returns the first. */
    private function reserve(string $kind, int $count): int
    {
        return DB::transaction(function () use ($kind, $count): int {
            $row = DB::table('demo_sequence')->where('kind', $kind)->lockForUpdate()->first();
            $first = ($row->last ?? 0) + 1;
            $last = $first + max(0, $count) - 1;
            $row ? DB::table('demo_sequence')->where('kind', $kind)->update(['last' => $last]) : DB::table('demo_sequence')->insert(['kind' => $kind, 'last' => $last]);

            return $first;
        });
    }

    /** A Poisson-distributed count with mean $mean (Knuth; normal approximation for large means). */
    private function poisson(float $mean): int
    {
        if ($mean <= 0) {
            return 0;
        }

        if ($mean > 50) {
            $u = max(1e-12, mt_rand() / mt_getrandmax());
            $v = mt_rand() / mt_getrandmax();

            return max(0, (int) round($mean + sqrt($mean) * sqrt(-2 * log($u)) * cos(2 * M_PI * $v)));
        }

        $limit = exp(-$mean);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $limit);

        return $k - 1;
    }
}
