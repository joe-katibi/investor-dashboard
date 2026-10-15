<?php

namespace App\Services\Reports;

use App\Support\AftercareCalls;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The 72-hour follow-up calls after site visits (CX Follow-up – Site Visit): every call, and how
 * customers feel 72 hours on — satisfaction, ratings, installation quality, technician conduct,
 * repeat faults (visits reopened) and new faults (tickets raised), with vandalism counted apart.
 */
class AftercareReports
{
    public const REPORTS = [
        'visit_aftercare' => 'Tickets: 72-hour Follow-up Calls (Site Visits)',
        'visit_aftercare_summary' => 'Tickets: 72-hour Follow-up Summary (Satisfaction)',
    ];

    public function run(string $key, array $filters = []): ReportResult
    {
        [$from, $to] = OutageQuery::period($filters);
        $from = CarbonImmutable::instance($from ?? now()->subDays(29)->startOfDay());
        $to = CarbonImmutable::instance($to ?? now()->endOfDay());
        $label = $from->format('d M Y').' – '.$to->format('d M Y');

        return $key === 'visit_aftercare' ? $this->register($from, $to, $label) : $this->summary($from, $to, $label);
    }

    /** @return Collection<int, array<string, mixed>> the calls of the period, oldest first */
    public function calls(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::table('ticket_follow_ups as f')
            ->join('outages as o', 'o.id', '=', 'f.outage_id')
            ->join('tickets as t', 't.outage_id', '=', 'o.id')
            ->leftJoin('visit_types as v', 'v.id', '=', 't.visit_type_id')
            ->leftJoin('users as a', 'a.id', '=', 'f.user_id')
            ->leftJoin('users as tech', 'tech.id', '=', 'o.technician_id')
            ->leftJoin('outages as n', 'n.id', '=', 'f.new_outage_id')
            ->where('f.kind', 'aftercare')
            ->whereBetween('f.called_at', [$from, $to])
            ->orderBy('f.called_at')->orderBy('f.id')
            ->get([
                'f.id', 'f.called_at', 'f.call_outcome', 'f.feedback', 'f.disposition', 'f.action_required', 'f.rating', 'f.service_working',
                'f.installation_quality', 'f.technician_conduct', 'f.notes', 'f.callback_at', 'o.ticket_number', 't.account_number', 't.customer_name',
                't.calling_number', 't.primary_number', 't.confirmed_at', 't.aftercare_due_at', 'v.name as visit_type', 'a.name as agent',
                'tech.name as technician', 'n.ticket_number as new_ticket',
            ])
            ->map(fn (object $r) => [
                'called' => Carbon::parse($r->called_at)->format('Y-m-d H:i'),
                'visit' => $r->ticket_number,
                'visit_type' => $r->visit_type,
                'account' => $r->account_number,
                'customer' => $r->customer_name,
                'phone' => $r->calling_number ?: $r->primary_number,
                'technician' => $r->technician,
                'confirmed' => $r->confirmed_at ? Carbon::parse($r->confirmed_at)->format('Y-m-d H:i') : null,
                'due' => $r->aftercare_due_at ? Carbon::parse($r->aftercare_due_at)->format('Y-m-d H:i') : null,
                'hours_after_due' => $r->aftercare_due_at ? round(Carbon::parse($r->aftercare_due_at)->diffInMinutes(Carbon::parse($r->called_at)) / 60, 1) : null,
                'agent' => $r->agent,
                'call_outcome' => AftercareCalls::CALL_OUTCOMES[$r->call_outcome] ?? $r->call_outcome,
                'feedback' => AftercareCalls::FEEDBACK[$r->feedback] ?? null,
                'main' => AftercareCalls::main($r->disposition),
                'disposition' => AftercareCalls::label($r->disposition) ?? $r->disposition,
                'action' => str((string) (AftercareCalls::ACTIONS[$r->action_required] ?? $r->action_required))->before(' — ')->toString(),
                'rating' => $r->rating !== null ? (int) $r->rating : null,
                'service_working' => $r->service_working === null ? null : ((bool) $r->service_working ? 'Yes' : 'No'),
                'installation_quality' => AftercareCalls::QUALITY[$r->installation_quality] ?? null,
                'technician_conduct' => AftercareCalls::QUALITY[$r->technician_conduct] ?? null,
                'comments' => $r->notes,
                'callback' => $r->callback_at ? Carbon::parse($r->callback_at)->format('Y-m-d H:i') : null,
                'new_ticket' => $r->new_ticket,
                // raw values for the summary
                '_reached' => $r->call_outcome === 'connected',
                '_feedback' => $r->feedback,
                '_reopened' => in_array($r->action_required, AftercareCalls::REOPENS, true),
                '_working' => $r->service_working === null ? null : (bool) $r->service_working,
                '_installation' => $r->installation_quality,
                '_conduct' => $r->technician_conduct,
            ]);
    }

    private function register(CarbonImmutable $from, CarbonImmutable $to, string $label): ReportResult
    {
        $rows = $this->calls($from, $to);
        $columns = [
            'called' => 'Follow-up Call', 'visit' => 'Visit No', 'visit_type' => 'Visit Type', 'account' => 'Account Number', 'customer' => 'Customer',
            'phone' => 'Phone', 'technician' => 'Technician', 'confirmed' => 'Visit Confirmed', 'due' => 'Call Due', 'hours_after_due' => 'Hrs After Due',
            'agent' => 'Agent', 'call_outcome' => 'Call Outcome', 'feedback' => 'Customer Feedback', 'main' => 'Disposition', 'disposition' => 'Sub-disposition',
            'action' => 'Action Required', 'rating' => 'Rating (1–5)', 'service_working' => 'Service Working', 'installation_quality' => 'Installation Quality',
            'technician_conduct' => 'Technician Conduct', 'comments' => 'Comments', 'callback' => 'Call Back At', 'new_ticket' => 'New Ticket',
        ];
        $reached = $rows->where('_reached', true);

        return new ReportResult(self::REPORTS['visit_aftercare'], $columns,
            fn (?int $limit) => ($limit ? $rows->take($limit) : $rows)->map(fn (array $row) => array_intersect_key($row, $columns))->values()->all(),
            [
                'Calls' => $rows->count(), 'Reached' => $reached->count(),
                'Satisfied' => $this->pct($reached->where('_feedback', 'satisfied')->count(), $reached->count()),
                'Average rating' => $reached->whereNotNull('rating')->isEmpty() ? '—' : round($reached->whereNotNull('rating')->avg('rating'), 1).' / 5',
            ],
            $label.'. Every call made 72 hours after a site visit was confirmed with the customer.');
    }

    private function summary(CarbonImmutable $from, CarbonImmutable $to, string $label): ReportResult
    {
        $calls = $this->calls($from, $to);
        $line = function (string $group, string $name, Collection $rows): array {
            $reached = $rows->where('_reached', true);
            $rated = $reached->whereNotNull('rating');

            return [
                'group' => $group, 'name' => $name, 'calls' => $rows->count(), 'reached' => $reached->count(),
                'satisfied' => $reached->where('_feedback', 'satisfied')->count(),
                'dissatisfied' => $reached->where('_feedback', 'dissatisfied')->count(),
                'vandalism' => $reached->where('_feedback', 'vandalism')->count(),
                'satisfied_pct' => $this->pct($reached->where('_feedback', 'satisfied')->count(), $reached->count()),
                'avg_rating' => $rated->isEmpty() ? null : round($rated->avg('rating'), 1),
                'working_pct' => $this->pct($reached->where('_working', true)->count(), $reached->whereNotNull('_working')->count()),
                'installation_pct' => $this->pct($reached->where('_installation', 'satisfactory')->count(), $reached->whereNotNull('_installation')->count()),
                'conduct_pct' => $this->pct($reached->where('_conduct', 'satisfactory')->count(), $reached->whereNotNull('_conduct')->count()),
                'reopened' => $rows->where('_reopened', true)->count(),
                'new_tickets' => $rows->whereNotNull('new_ticket')->count(),
            ];
        };
        $by = fn (string $group, string $key) => $calls->groupBy(fn (array $row) => (string) ($row[$key] ?? '—'))
            ->map(fn (Collection $rows, string $name) => $line($group, $name, $rows))->sortByDesc('calls')->values();

        $rows = collect([$line('All', 'All follow-up calls', $calls)])
            ->merge($by('Customer feedback', 'feedback')->reject(fn (array $row) => $row['name'] === '—'))
            ->merge($by('Disposition', 'main'))
            ->merge($by('Sub-disposition', 'disposition'))
            ->merge($by('Action required', 'action'))
            ->merge($by('Technician', 'technician'))
            ->merge($by('Visit type', 'visit_type'))
            ->merge($by('Agent', 'agent'));

        // Calls due in the period, and how quickly the first call was made.
        $due = DB::table('tickets')->whereBetween('aftercare_due_at', [$from, $to])->get(['outage_id', 'aftercare_due_at', 'aftercare_done_at']);
        $first = DB::table('ticket_follow_ups')->where('kind', 'aftercare')->whereIn('outage_id', $due->pluck('outage_id'))
            ->groupBy('outage_id')->selectRaw('outage_id, MIN(called_at) as first_call')->pluck('first_call', 'outage_id');
        $onTime = $due->filter(fn (object $t) => isset($first[$t->outage_id]) && Carbon::parse($t->aftercare_due_at)->diffInHours(Carbon::parse($first[$t->outage_id])) <= 24)->count();

        return new ReportResult(self::REPORTS['visit_aftercare_summary'], [
            'group' => 'By', 'name' => 'Name', 'calls' => 'Calls', 'reached' => 'Reached', 'satisfied' => 'Satisfied', 'dissatisfied' => 'Dissatisfied',
            'vandalism' => 'Vandalism / Damage', 'satisfied_pct' => 'Satisfied %', 'avg_rating' => 'Avg Rating (1–5)', 'working_pct' => 'Service Working %',
            'installation_pct' => 'Installation Satisfactory %', 'conduct_pct' => 'Technician Conduct Satisfactory %', 'reopened' => 'Visits Reopened (same issue)',
            'new_tickets' => 'New Tickets (new issue)',
        ], fn (?int $limit) => $limit ? $rows->take($limit)->all() : $rows->all(), [
            'Calls due' => $due->count(),
            'Called within 24 h of due' => $this->pct($onTime, $due->count()),
            'Still to call' => DB::table('tickets')->whereNotNull('aftercare_due_at')->whereNull('aftercare_done_at')->count(),
            'Satisfied' => $rows->first()['satisfied_pct'],
        ], $label.'. Satisfaction 72 hours after a site visit. Percentages are of the customers reached; vandalism is counted apart from dissatisfaction. "Visits reopened": the same issue came back; "New tickets": a different issue.');
    }

    private function pct(int $part, int $whole): string
    {
        return $whole > 0 ? round($part / $whole * 100, 1).'%' : '—';
    }
}
