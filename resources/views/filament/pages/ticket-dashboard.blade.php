<x-filament-panels::page>
    @php
        $c = $s['counts'];
        $tile = fn (string $label, $value, ?string $hint = null, string $tone = '', ?string $url = null) => compact('label', 'value', 'hint', 'tone', 'url');

        $live = [
            $tile('Pending', $c['pending'], "{$c['tickets']} tickets · {$c['visits']} visits", '', $ticketTab('open')),
            $tile('Within SLA', $c['within'], 'On time', $c['within'] > 0 ? 'oms-ok' : ''),
            $tile('At risk', $c['at_risk'], 'SLA clock nearly gone', $c['at_risk'] > 0 ? 'oms-warn' : '', $ticketTab('sla')),
            $tile('Breached', $c['breached'], 'Past the SLA, still open', $c['breached'] > 0 ? 'oms-bad' : '', $visitTab('sla')),
            $tile('In follow-up', $c['in_follow_up'], 'Pending past the follow-up time — ready for credit', $c['in_follow_up'] > 0 ? 'oms-warn' : '', $visitTab('follow_up')),
            $tile('Waiting for a person', $c['unassigned'], 'In a queue, nobody on it yet', $c['unassigned'] > 0 ? 'oms-warn' : ''),
            $tile('Closed today', $c['closed'], $pct($c['closed_pct']).' within SLA', '', $visitTab('closed')),
            $tile('Closed past SLA today', $c['closed_breached'], "{$c['closed_met']} within SLA", $c['closed_breached'] > 0 ? 'oms-bad' : ''),
        ];

        $crosstabs = [
            'SLA per department — pending tickets by time open (SLA hours)' => $s['by_department'],
            'SLA per region' => $s['by_region'],
            'SLA per OLT' => $s['by_olt'],
            'Issue per OLT' => $s['issue_by_olt'],
        ];
    @endphp

    <div wire:poll.120s class="oms-stack">
        <x-filament::section heading="Now" compact>
            <div class="oms-stats oms-stats-tight">
                @foreach ($live as $t)
                    @if ($t['url'])
                        <a class="oms-stat" href="{{ $t['url'] }}" wire:navigate>
                    @else
                        <div class="oms-stat">
                    @endif
                        <div class="oms-label">{{ $t['label'] }}</div>
                        <div class="oms-stat-value {{ $t['tone'] }}">{{ $t['value'] }}</div>
                        <div class="oms-label">{{ $t['hint'] }}</div>
                    @if ($t['url'])
                        </a>
                    @else
                        </div>
                    @endif
                @endforeach
            </div>
        </x-filament::section>

        <div class="oms-grid">
            <x-filament::section :heading="'Ticket movement — '.$period['label']" :description="'Compared with '.$period['previous_label']" compact>
                <div class="oms-scroll"><table class="oms-table">
                    <thead><tr><th></th><th>{{ $period['previous_label'] }}</th><th>{{ $period['label'] }}</th><th>Change</th></tr></thead>
                    <tbody>
                    @foreach ($period['headline'] as $row)
                        <tr><td class="oms-value">{{ $row[0] }}</td><td>{{ $row[1] }}</td><td class="oms-value">{{ $row[2] }}</td><td>{{ $row[3] }}</td></tr>
                    @endforeach
                    </tbody>
                </table></div>
                <div class="oms-scroll"><table class="oms-table">
                    <thead><tr><th></th><th>All</th><th>Customer tickets</th><th>Site visits</th></tr></thead>
                    <tbody>
                    @foreach ($period['movement'] as $row)
                        <tr><td>{{ $row[0] }}</td><td class="oms-value">{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td></tr>
                    @endforeach
                    </tbody>
                </table></div>
            </x-filament::section>

            <x-filament::section heading="Reasons for pending" description="Recorded reason for delay on the tickets still open" compact>
                <div class="oms-scroll"><table class="oms-table">
                    <thead><tr><th>Reason for delay</th><th>Pending</th><th>% of pending</th><th>Within our control?</th></tr></thead>
                    <tbody>
                    @forelse ($period['reasons'] as $row)
                        <tr><td>{{ $row[0] }}</td><td class="oms-value">{{ $row[1] }}</td><td>{{ $row[2] }}</td><td class="{{ $row[3] === 'Yes' ? 'oms-warn' : '' }}">{{ $row[3] }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="oms-muted">No pending tickets.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </x-filament::section>
        </div>

        @foreach ($crosstabs as $heading => $table)
            <x-filament::section :heading="$heading" compact collapsible>
                <div class="oms-scroll"><table class="oms-table">
                    <thead><tr><th>{{ $table['heading'] }}</th>@foreach ($table['columns'] as $column)<th>{{ $column }}</th>@endforeach<th>Total</th></tr></thead>
                    <tbody>
                    @forelse (array_slice($table['rows'], 0, 40) as $row)
                        <tr>
                            <td class="oms-value">{{ $row['label'] }}</td>
                            @foreach ($row['cells'] as $cell)<td class="{{ $cell === 0 ? 'oms-muted' : '' }}">{{ $cell }}</td>@endforeach
                            <td class="oms-value">{{ $row['total'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($table['columns']) + 2 }}" class="oms-muted">No pending tickets.</td></tr>
                    @endforelse
                    @if ($table['rows'] !== [])
                        <tr><td class="oms-value">Total</td>@foreach ($table['totals'] as $total)<td class="oms-value">{{ $total }}</td>@endforeach<td class="oms-value">{{ $table['total'] }}</td></tr>
                    @endif
                    </tbody>
                </table></div>
            </x-filament::section>
        @endforeach

        <div class="oms-grid">
            <x-filament::section heading="Inflow vs closed — last 14 days" compact>
                <div class="oms-scroll oms-email">{!! $inflowChart !!}</div>
            </x-filament::section>
            <x-filament::section heading="Closed within SLA % — last 14 days" compact>
                <div class="oms-scroll oms-email">{!! $slaChart !!}</div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Longest pending" icon="heroicon-o-clock" compact>
            <div class="oms-scroll"><table class="oms-table">
                <thead><tr><th>Ticket</th><th>Customer</th><th>Issue</th><th>Region / OLT</th><th>Department</th><th>With</th><th>Status</th><th>SLA hrs</th><th>SLA</th><th>Reason for delay</th></tr></thead>
                <tbody>
                @forelse ($s['oldest'] as $row)
                    <tr>
                        <td><a class="underline oms-value" href="{{ $url($row) }}" wire:navigate>{{ $row['ticket'] }}</a></td>
                        <td>{{ \Illuminate\Support\Str::limit((string) $row['customer'], 26) }}<div class="oms-muted">{{ $row['account'] }}</div></td>
                        <td>{{ $row['issue'] }}</td>
                        <td>{{ $row['region'] }}<div class="oms-muted">{{ $row['olt'] }}</div></td>
                        <td>{{ $row['department'] }}</td>
                        <td>{{ $row['assignee'] ?? 'Waiting (FIFO)' }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td class="oms-value">{{ $row['sla_hours'] }}</td>
                        <td class="{{ ['breached' => 'oms-bad', 'at_risk' => 'oms-warn', 'within' => 'oms-ok'][$row['state']] }}">{{ \App\Services\Tickets\TicketMetrics::STATES[$row['state']] }}</td>
                        <td>{{ $row['delay_reason'] ?? '—' }}@if ($row['in_follow_up'])<div class="oms-muted">{{ $row['followed_up'] ? 'customer called' : 'follow-up due' }}</div>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="oms-muted">Nothing pending.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
