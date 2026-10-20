<?php

namespace App\Services\Tickets;

use App\Models\Outage;
use App\Models\Vertical;
use App\Services\Reports\FourGTicketReports;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Placeholder values of the scheduled 4G ticket e-mails (Configuration → Email Templates):
 *
 *   ticket_4g_update   every 2 hours: the last N hours ("Period (hours)") and everything still open
 *   ticket_4g_daily    07:00 and 19:00: the last 12 hours
 *   ticket_4g_weekly   Monday 07:00: the week just ended, against the week before
 *   ticket_4g_monthly  the 1st at 06:00: the month just ended, against the month before
 *
 * Counts are plain values ({{t4_open}}); everything ending in _table is a ready-made HTML table.
 */
class FourGTicketReportData
{
    public const TYPES = [
        'ticket_4g_update' => 'update', 'ticket_4g_daily' => 'day', 'ticket_4g_weekly' => 'week', 'ticket_4g_monthly' => 'month',
    ];

    /** Since midnight (the executive live page; not an e-mail type). */
    public const TODAY = 'ticket_4g_today';

    /** Placeholders offered on the e-mail template screen. */
    public const PLACEHOLDERS = [
        'report_time', 'report_date', 't4_title', 't4_period_label', 't4_previous_label', 't4_open', 't4_raised', 't4_closed', 't4_closed_sla_pct',
        't4_breached_open', 't4_breaches', 't4_with_vendor', 't4_waiting', 't4_headline_table', 't4_compare_table', 't4_department_table',
        't4_issue_table', 't4_pending_table', 't4_breach_table', 't4_breach_people_table', 't4_vendor_table',
    ];

    public function __construct(
        private readonly FourGTicketReports $reports,
        private readonly TicketMetrics $metrics,
    ) {}

    public static function handles(?string $reportType): bool
    {
        return isset(self::TYPES[$reportType]);
    }

    /**
     * The period of an e-mail: [from, to, label, previous from, previous to, previous label, title].
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string, 3: CarbonImmutable, 4: CarbonImmutable, 5: string, 6: string}
     */
    public function period(string $reportType, CarbonInterface $now, int $periodHours): array
    {
        $now = CarbonImmutable::instance($now);
        $range = fn (CarbonImmutable $a, CarbonImmutable $b) => $a->isSameDay($b) ? $a->format('d M Y H:i').' – '.$b->format('H:i') : $a->format('d M H:i').' – '.$b->format('d M Y H:i');

        switch ($reportType === self::TODAY ? 'today' : (self::TYPES[$reportType] ?? 'update')) {
            case 'today':
                $from = $now->startOfDay();

                return [$from, $now, 'Today from 00:00', $from->subDay(), $now->subDay(), 'Yesterday to '.$now->format('H:i'), '4G Tickets – Today'];
            case 'week':
                $from = $now->subWeek()->startOfWeek();
                $to = $from->endOfWeek();

                return [$from, $to, 'Week of '.$from->format('d M').' – '.$to->format('d M Y'), $from->subWeek(), $to->subWeek(), 'Week before', '4G Tickets – Weekly Summary'];
            case 'month':
                $from = $now->subMonthNoOverflow()->startOfMonth();
                $to = $from->endOfMonth();

                return [$from, $to, $from->format('F Y'), $from->subMonthNoOverflow()->startOfMonth(), $from->subMonthNoOverflow()->endOfMonth(),
                    $from->subMonthNoOverflow()->format('F Y'), '4G Tickets – Monthly Summary'];
            default:
                $hours = max(1, $periodHours);
                $from = $now->subHours($hours);

                return [$from, $now, $range($from, $now), $from->subHours($hours), $from, 'The '.$hours.' hours before',
                    (self::TYPES[$reportType] ?? 'update') === 'day' ? '4G Tickets – Daily Report' : '4G Tickets – '.$hours.'-Hour Update'];
        }
    }

    /** @return array<string, scalar> */
    public function build(string $reportType, ?CarbonInterface $now = null, int $periodHours = 2): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        [$from, $to, $label, $prevFrom, $prevTo, $prevLabel, $title] = $this->period($reportType, $now, $periodHours);

        $records = $this->reports->records($from, $to);
        $open = $records->where('closed', false);
        $breaches = $this->reports->breaches($from, $to);
        $current = $this->movement($from, $to, $breaches->count());
        $previous = $this->movement($prevFrom, $prevTo, $this->reports->breaches($prevFrom, $prevTo)->count());

        $line = fn (array $row) => [$row['name'], $row['total'], $row['waiting'], $row['in_progress'], $row['vendor'], $row['callback'], $row['closed'], $row['breached']];
        $headers = ['', 'Total', 'Open (waiting)', 'In progress', 'With vendor', 'Customer call-back', 'Closed', 'SLA breached'];
        $tones = [null, null, 'warn', null, 'warn', null, 'ok', 'bad'];
        $all = $this->reports->line('Total', $records);

        return [
            'report_time' => $now->format('H:i'),
            'report_date' => $now->format('d F Y'),
            't4_title' => $title,
            't4_period_label' => $label,
            't4_previous_label' => $prevLabel,
            't4_open' => $open->count(),
            't4_raised' => $current['raised'],
            't4_closed' => $current['closed'],
            't4_closed_sla_pct' => $current['sla_pct'],
            't4_breached_open' => $open->where('breached', true)->count(),
            't4_breaches' => $breaches->count(),
            't4_with_vendor' => $open->where('state', 'vendor')->count(),
            't4_waiting' => $open->where('state', 'waiting')->count(),
            't4_headline_table' => TicketTables::tiles([
                ['Open now', $open->count(), $open->where('state', 'waiting')->count().' waiting for someone'],
                ['Raised', $current['raised'], $label],
                ['Closed', $current['closed'], $current['sla_pct'].' within SLA', 'ok'],
                ['Breached – open', $open->where('breached', true)->count(), null, $open->where('breached', true)->isNotEmpty() ? 'bad' : null],
                ['SLA breaches', $breaches->count(), 'in the period', $breaches->isNotEmpty() ? 'warn' : null],
                ['With vendors', $open->where('state', 'vendor')->count(), null, $open->where('state', 'vendor')->isNotEmpty() ? 'warn' : null],
            ]),
            't4_compare_table' => TicketTables::table(['', $prevLabel, $label, 'Change'], [
                ['Raised', $previous['raised'], $current['raised'], $this->change($previous['raised'], $current['raised'])],
                ['Closed', $previous['closed'], $current['closed'], $this->change($previous['closed'], $current['closed'])],
                ['Closed within SLA %', $previous['sla_pct'], $current['sla_pct'], ''],
                ['SLA breaches', $previous['breaches'], $current['breaches'], $this->change($previous['breaches'], $current['breaches'])],
            ], ['bold_first' => true]),
            't4_department_table' => TicketTables::table($headers, array_map($line, $this->reports->crosstab($records, 'department', $this->reports->deskOrder())),
                ['tones' => $tones, 'total' => $line($all), 'empty' => 'No 4G tickets in the period and none open.']),
            't4_issue_table' => TicketTables::table(['Issue reported', ...array_slice($headers, 1)], array_map($line, array_slice($this->reports->crosstab($records, 'issue'), 0, 40)),
                ['tones' => $tones, 'total' => $line($all), 'empty' => 'No 4G tickets in the period and none open.']),
            't4_pending_table' => TicketTables::table(['Department', 'Pending with', 'Open', 'Breached', 'Oldest (hrs)'],
                array_map(fn (array $row) => [$row['department'], $row['with'], $row['open'], $row['breached'], $row['oldest_hours']], $this->reports->withWhom($open)),
                ['left' => 2, 'tones' => [null, null, null, 'bad'], 'empty' => 'No 4G ticket is open.']),
            't4_breach_table' => TicketTables::table(['Breached at', 'Ticket', 'What was breached', 'Department', 'With', 'Over by (min)', 'Now'],
                $breaches->take(60)->map(fn (array $row) => [$row['when'], $row['ticket'], $row['kind'], $row['desk'] ?? '—', $row['held_by'] ?? '—', $row['over'], $row['state']])->all(),
                ['left' => 5, 'empty' => 'No SLA was breached in the period.']),
            't4_breach_people_table' => TicketTables::table(['Department', 'With', 'Breaches'],
                $breaches->groupBy(fn (array $row) => ($row['desk'] ?? '—').'|'.($row['held_by'] ?? 'Nobody (waiting)'))
                    ->map(fn ($rows, string $key) => [...explode('|', $key, 2), $rows->count()])->sortByDesc(2)->values()->all(),
                ['left' => 2, 'tones' => [null, null, 'bad'], 'empty' => 'No SLA was breached in the period.']),
            't4_vendor_table' => TicketTables::table(['Vendor', 'Called in', 'Waiting for vendor now', 'Feedback received', 'Closed', 'Avg hrs to feedback'],
                array_map(fn (array $row) => [$row['vendor'], $row['escalated'], $row['waiting'], $row['answered'], $row['closed'], $row['avg_hours'] ?? '—'],
                    $this->reports->vendors($this->reports->base($from, $to)->get())),
                ['tones' => [null, null, 'warn'], 'empty' => 'No vendor was called in.']),
        ];
    }

    /** @return array{raised: int, closed: int, sla_pct: string, breaches: int} */
    private function movement(CarbonInterface $from, CarbonInterface $to, int $breaches): array
    {
        $fourG = fn () => Outage::query()->cxTickets()->where('vertical_id', Vertical::idFor(Vertical::MOBILITY_4G) ?? 0);
        $closed = $fourG()->where('is_closed', true)->whereBetween('resolved_at', [$from, $to])->get(['sla_state'])->countBy(fn (Outage $o) => $o->sla_state?->value);
        $judged = $closed->get('met', 0) + $closed->get('missed', 0);

        return [
            'raised' => $fourG()->whereBetween('start_time', [$from, $to])->count(),
            'closed' => $closed->sum(),
            'sla_pct' => $judged > 0 ? $this->metrics->pct(round($closed->get('met', 0) / $judged * 100, 1)) : '—',
            'breaches' => $breaches,
        ];
    }

    private function change(int $before, int $now): string
    {
        return match (true) {
            $before === $now => '–',
            $before === 0 => '+'.$now,
            default => ($now > $before ? '+' : '').Str::of((string) round(($now - $before) / $before * 100))->append('%'),
        };
    }
}
