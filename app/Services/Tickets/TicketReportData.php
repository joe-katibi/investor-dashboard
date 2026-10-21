<?php

namespace App\Services\Tickets;

use App\Services\Reports\InsightReports;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Placeholder values of the scheduled ticket e-mails (Configuration → Email Templates):
 *
 *   ticket_pending   the pending / SLA update sent every 3 hours
 *   ticket_daily     the daily report (07:00, 12:00, 19:00)
 *   ticket_weekly    Monday 07:00, the week just ended
 *   ticket_monthly   the 1st at 07:00, the month just ended
 *   ticket_final_reasons  what tickets were closed with, day by day for seven days
 *
 * Counts are plain values ({{tk_pending}}); everything ending in _table is a ready-made HTML
 * table or chart ({{tk_sla_per_department_table}}, {{tk_inflow_chart_table}}…).
 */
class TicketReportData
{
    public const TYPES = [
        'ticket_pending' => 'pending', 'ticket_daily' => 'day', 'ticket_weekly' => 'week', 'ticket_monthly' => 'month', 'ticket_final_reasons' => 'reasons',
    ];

    /** Placeholders offered on the e-mail template screen. */
    public const PLACEHOLDERS = [
        'report_time', 'report_date', 'tk_sla_clock', 'tk_period_hours', 'tk_followup_hours', 'tk_pending', 'tk_pending_within', 'tk_pending_at_risk',
        'tk_pending_breached', 'tk_in_follow_up', 'tk_closed', 'tk_closed_sla_pct', 'tk_closed_today', 'tk_headline_table', 'tk_sla_per_department_table',
        'tk_sla_per_olt_table', 'tk_issue_per_olt_table', 'tk_sla_per_region_table', 'tk_follow_up_table', 'tk_closed_table', 'tk_report_title',
        'tk_period_label', 'tk_previous_label', 'tk_movement_table', 'tk_closed_sla_table', 'tk_pending_sla_table', 'tk_inflow_chart_table',
        'tk_sla_chart_table', 'tk_region_table', 'tk_pending_reasons_table', 'tk_trend_table', 'tk_raised', 'tk_raised_table', 'tk_final_reasons_table',
        'tk_reasons_total', 'tk_reasons_previous', 'tk_reasons_until',
    ];

    public function __construct(
        private readonly TicketMetrics $metrics,
        private readonly BusinessClock $clock,
        private readonly Settings $settings,
        private readonly InsightReports $insights,
    ) {}

    public static function handles(?string $reportType): bool
    {
        return isset(self::TYPES[$reportType]);
    }

    /** @return array<string, scalar> */
    /** @param  array{kind?: string, vertical_ids?: list<int>}  $filters  e.g. FTTH customer tickets or site visits only (the executive live page) */
    public function build(string $reportType, ?CarbonInterface $now = null, int $periodHours = 3, array $filters = []): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $kind = self::TYPES[$reportType] ?? 'pending';

        $data = [
            'report_time' => $now->format('H:i'),
            'report_date' => $now->format('d F Y'),
            'period_start' => $now->subHours(max(1, $periodHours))->format('Y-m-d H:i'),
            'period_end' => $now->format('Y-m-d H:i'),
            'tk_sla_clock' => $this->clock->describe(),
            'tk_period_hours' => $periodHours,
            'tk_followup_hours' => round($this->settings->int('ticketing.followup_minutes') / 60, 1),
        ];

        return $data + $this->finalReasons($now) + match ($kind) {
            'pending' => $this->pending($now, $periodHours, $filters),
            'reasons' => [],
            default => $this->period($kind, $now),
        };
    }

    /** @return array<string, scalar> */
    private function pending(CarbonImmutable $now, int $periodHours, array $filters = []): array
    {
        $s = $this->metrics->snapshot($now, $periodHours, $filters);
        $c = $s['counts'];
        $tones = $this->tierTones();

        return [
            'tk_pending' => $c['pending'],
            'tk_pending_within' => $c['within'],
            'tk_pending_at_risk' => $c['at_risk'],
            'tk_pending_breached' => $c['breached'],
            'tk_in_follow_up' => $c['in_follow_up'],
            'tk_closed' => $c['closed'],
            'tk_closed_sla_pct' => $this->metrics->pct($c['closed_pct']),
            'tk_headline_table' => TicketTables::tiles([
                ['Pending', $c['pending'], "{$c['tickets']} tickets · {$c['visits']} visits"],
                ['Within SLA', $c['within'], null, 'ok'],
                ['At risk', $c['at_risk'], null, $c['at_risk'] > 0 ? 'warn' : null],
                ['Breached', $c['breached'], null, $c['breached'] > 0 ? 'bad' : null],
                ['In follow-up', $c['in_follow_up'], 'ready for credit', $c['in_follow_up'] > 0 ? 'warn' : null],
                ["Closed ({$periodHours}h)", $c['closed'], $this->metrics->pct($c['closed_pct']).' within SLA'],
                ['Closed today', $c['closed_today'], $this->metrics->pct($c['closed_today_pct']).' within SLA'],
            ]),
            'tk_closed_today' => $c['closed_today'],
            'tk_sla_per_department_table' => TicketTables::crosstab($s['by_department'], $tones),
            'tk_sla_per_olt_table' => TicketTables::crosstab($s['by_olt'], $tones),
            'tk_issue_per_olt_table' => TicketTables::crosstab($s['issue_by_olt']),
            'tk_sla_per_region_table' => TicketTables::crosstab($s['by_region'], $tones),
            'tk_follow_up_table' => TicketTables::table(
                ['Ticket', 'Account', 'Customer', 'Issue', 'OLT', 'Region', 'Department', 'With', 'SLA hrs', 'Reason for delay', 'Called'],
                $s['follow_up']->take(60)->map(fn (array $row) => [
                    $row['ticket'], $row['account'], Str::limit((string) $row['customer'], 28), $row['issue'], $row['olt'], $row['region'], $row['department'],
                    $row['assignee'] ?? '—', $row['sla_hours'], $row['delay_reason'] ?? '—', $row['followed_up'] ? 'Yes' : 'No',
                ])->all(),
                ['left' => 8, 'empty' => 'No ticket is past the follow-up time.'],
            ),
            'tk_closed_table' => $this->closedTable($s['closed']),
            'tk_raised' => ($raised = $this->metrics->raised($now->subHours(max(1, $periodHours)), $now))->count(),
            'tk_raised_table' => TicketTables::table(
                ['Ticket', 'Account', 'Customer', 'Issue', 'OLT', 'Region', 'Department', 'With', 'Status'],
                $raised->take(60)->map(fn (array $row) => [
                    $row['ticket'], $row['account'], Str::limit((string) $row['customer'], 28), $row['issue'], $row['olt'], $row['region'], $row['department'],
                    $row['assignee'] ?? 'Waiting (FIFO)', $row['status'],
                ])->all(),
                ['left' => 9, 'empty' => 'No ticket was raised in the period.'],
            ),
        ];
    }

    /**
     * What tickets were closed with over the last seven days, against the seven days before.
     *
     * @return array<string, scalar>
     */
    private function finalReasons(CarbonImmutable $now): array
    {
        $trend = $this->insights->finalReasons($now, 7);

        return [
            'tk_reasons_total' => $trend['total'],
            'tk_reasons_previous' => $trend['previous'],
            'tk_reasons_until' => $trend['until'],
            'tk_final_reasons_table' => TicketTables::table(
                ['Final reason', ...$trend['days'], 'Total', 'Share', 'Previous 7 days', 'Change'],
                array_map(fn (array $row) => [
                    $row['reason'], ...array_map(fn (int $i) => $row["d{$i}"], array_keys($trend['days'])), $row['total'], $row['share'], $row['previous'], $row['change'],
                ], array_slice($trend['rows'], 0, 40)),
                ['left' => 1, 'bold_first' => true, 'empty' => 'Nothing was closed in the last 14 days.'],
            ),
        ];
    }

    /** @return array<string, scalar> */
    private function period(string $kind, CarbonImmutable $now): array
    {
        $p = $this->metrics->period($kind, $now);
        $trend = collect($p['trend']);
        $labels = $trend->pluck('label')->all();
        $columns = ['', 'All', 'Customer tickets', 'Site visits'];
        $selected = $p['headline'];

        return [
            'tk_report_title' => $p['title'],
            'tk_period_label' => $p['label'],
            'tk_previous_label' => $p['previous_label'],
            'tk_pending' => $selected[5][2],
            'tk_closed' => $selected[1][2],
            'tk_closed_sla_pct' => $selected[4][2],
            'tk_headline_table' => TicketTables::table(['', $p['previous_label'], $p['label'], 'Change'], $selected, ['bold_first' => true]),
            'tk_movement_table' => TicketTables::table($columns, $p['movement'], ['bold_first' => true]),
            'tk_closed_sla_table' => TicketTables::table($columns, $p['closed_sla'], ['bold_first' => true]),
            'tk_pending_sla_table' => TicketTables::table($columns, $p['pending_sla'], ['bold_first' => true, 'tones' => []]),
            'tk_inflow_chart_table' => TicketTables::bars($labels, [
                'Inflow' => ['color' => '#2563eb', 'values' => $trend->pluck('inflow')->all()],
                'Closed' => ['color' => '#16a34a', 'values' => $trend->pluck('closed')->all()],
            ]),
            'tk_sla_chart_table' => TicketTables::line($labels, [
                'Closed within SLA %' => ['color' => '#0d9488', 'values' => $trend->pluck('pct')->all()],
            ], '%', 100.0),
            'tk_region_table' => TicketTables::table(['Region', 'Inflow', 'Closed', 'Closed within SLA %', 'Pending', 'Pending – breached'], $p['regions'], ['tones' => [null, null, null, null, null, 'bad']]),
            'tk_sla_per_department_table' => TicketTables::crosstab($p['by_department'], $this->tierTones()),
            'tk_pending_reasons_table' => TicketTables::table(['Reason for delay', 'Pending tickets', '% of pending', 'Within our control?'], $p['reasons'], ['empty' => 'No pending tickets.']),
            'tk_trend_table' => TicketTables::table(
                [['day' => 'Date', 'week' => 'Week starting', 'month' => 'Month'][$kind], 'Inflow', 'Closed', 'Closed within SLA', 'Closed but breached', 'Closed within SLA %', 'Pending at the end'],
                $trend->map(fn (array $row) => [$row['label'], $row['inflow'], $row['closed'], $row['met'], $row['breached'], $this->metrics->pct($row['pct']), $row['pending']])->all(),
            ),
        ];
    }

    private function closedTable(Collection $closed): string
    {
        return TicketTables::table(
            ['Ticket', 'Account', 'Issue', 'OLT', 'Region', 'Technician', 'SLA hrs', 'SLA', 'Final reason'],
            $closed->take(60)->map(fn (array $row) => [
                $row['ticket'], $row['account'], $row['issue'], $row['olt'], $row['region'], $row['technician'] ?? '—', $row['sla_hours'],
                ['met' => 'Met', 'breached' => 'Breached', 'none' => '—'][$row['result']], Str::limit((string) $row['final_reason'], 70),
            ])->all(),
            ['left' => 6, 'empty' => 'Nothing was closed in the period.'],
        );
    }

    /**
     * Colour per TAT bracket column: within the SLA target green, up to three times it amber, beyond red.
     *
     * @return list<string>
     */
    private function tierTones(): array
    {
        $target = max(1, $this->settings->int('ticketing.sla_minutes')) / 60;
        $limits = array_map('intval', array_map(fn (string $label) => (string) preg_replace('/\D+/', '', $label), $this->metrics->tierLabels()));
        $last = count($limits) - 1;

        return array_map(fn (int $hours, int $i) => match (true) {
            $i === $last => 'bad',
            $hours <= $target => 'ok',
            $hours <= $target * 3 => 'warn',
            default => 'bad',
        }, $limits, array_keys($limits));
    }
}
