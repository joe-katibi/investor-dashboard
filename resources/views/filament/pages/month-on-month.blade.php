<x-filament-panels::page>
    @php
        $pct = fn ($v) => $v === null ? '—' : number_format($v, 1).'%';
        $change = fn ($v) => $v === null ? '' : ($v > 0 ? '▲ ' : ($v < 0 ? '▼ ' : '')).number_format(abs($v), 1).' pts';
        $tone = fn ($v) => $v === null ? 'oms-muted' : ($v > 0 ? 'oms-ok' : ($v < 0 ? 'oms-bad' : 'oms-muted'));
        $isOutages = $kind === 'outages';
        $finished = $isOutages ? 'Restored' : 'Closed';
    @endphp

    <div class="oms-stack">
        <x-filament::section compact>
            <div class="oms-stats oms-stats-tight">
                <div class="oms-stat">
                    <div class="oms-label">Within SLA — {{ $latest['label'] ?? 'latest month' }}</div>
                    <div class="oms-stat-value">{{ $pct($latest[$kind]['pct'] ?? null) }}</div>
                    <div class="oms-label">
                        <span class="{{ $tone($latest[$kind]['change'] ?? null) }}">{{ $change($latest[$kind]['change'] ?? null) ?: 'no earlier month' }}</span>
                        @if (($latest[$kind]['change'] ?? null) !== null) vs the month before @endif
                    </div>
                </div>
                <div class="oms-stat">
                    <div class="oms-label">Within SLA — all months</div>
                    <div class="oms-stat-value">{{ $pct($total['pct']) }}</div>
                    <div class="oms-label">{{ number_format($total['met']) }} of {{ number_format($total['measured']) }} {{ $noun }}</div>
                </div>
                <div class="oms-stat">
                    <div class="oms-label">Best month</div>
                    <div class="oms-stat-value">{{ $pct($best[$kind]['pct'] ?? null) }}</div>
                    <div class="oms-label">{{ $best['label'] ?? '—' }}</div>
                </div>
                <div class="oms-stat">
                    <div class="oms-label">{{ ucfirst($noun) }} raised</div>
                    <div class="oms-stat-value">{{ number_format($total['total']) }}</div>
                    <div class="oms-label">{{ $pct($total['closed_pct']) }} {{ strtolower($finished) }}@if ($isOutages) · {{ number_format($total['customers']) }} customers affected @endif</div>
                </div>
            </div>
        </x-filament::section>

        @livewire($chart)

        <x-filament::section heading="Month by month" :description="'Within SLA = '.$rule.'. Newest month first.'" compact>
            <div class="oms-scroll"><table class="oms-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Raised</th>
                        <th>{{ $finished }}</th>
                        <th>Within SLA</th>
                        <th>Outside SLA</th>
                        <th>Within SLA %</th>
                        <th>vs month before</th>
                        @if ($isOutages)
                            <th>Customers affected</th>
                            <th>Avg time to restore</th>
                        @else
                            <th>Avg time to complete</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                @forelse ($months as $m)
                    @php $s = $m[$kind]; @endphp
                    <tr>
                        <td class="oms-value">{{ $m['label'] }}</td>
                        <td>{{ $s['total'] ? number_format($s['total']) : '—' }}</td>
                        <td>{{ $s['total'] ? number_format($s['closed']).' ('.$pct($s['closed_pct']).')' : '—' }}</td>
                        <td>{{ $s['measured'] ? number_format($s['met']) : '—' }}</td>
                        <td>{{ $s['measured'] ? number_format($s['missed']) : '—' }}</td>
                        <td class="oms-value">{{ $pct($s['pct']) }}@if ($s['measured'] && ! $s['enough']) <span class="oms-label">(only {{ $s['measured'] }} measured)</span>@endif</td>
                        <td class="{{ $tone($s['change']) }}">{{ $change($s['change']) ?: '—' }}</td>
                        @if ($isOutages)
                            <td>{{ $s['total'] ? number_format($s['customers']) : '—' }}</td>
                            <td>{{ $s['avg_hours'] !== null ? number_format($s['avg_hours'], 1).' h' : '—' }}</td>
                        @else
                            <td>{{ $s['avg_hours'] !== null ? number_format($s['avg_hours'], 1).' business h' : '—' }}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="9" class="oms-muted">No {{ $noun }} yet.</td></tr>
                @endforelse
                <tr>
                    <td class="oms-value">All months</td>
                    <td class="oms-value">{{ number_format($total['total']) }}</td>
                    <td class="oms-value">{{ number_format($total['closed']) }} ({{ $pct($total['closed_pct']) }})</td>
                    <td class="oms-value">{{ number_format($total['met']) }}</td>
                    <td class="oms-value">{{ number_format($total['missed']) }}</td>
                    <td class="oms-value">{{ $pct($total['pct']) }}</td>
                    <td></td>
                    @if ($isOutages)
                        <td class="oms-value">{{ number_format($total['customers']) }}</td>
                        <td class="oms-value">{{ $total['avg_hours'] !== null ? number_format($total['avg_hours'], 1).' h' : '—' }}</td>
                    @else
                        <td class="oms-value">{{ $total['avg_hours'] !== null ? number_format($total['avg_hours'], 1).' business h' : '—' }}</td>
                    @endif
                </tr>
                </tbody>
            </table></div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
