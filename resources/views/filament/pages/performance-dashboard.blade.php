<x-filament-panels::page>
    @php
        $pct = fn ($v) => $v === null ? '—' : number_format($v, 1).'%';
        $tone = fn ($v, $target) => $v === null ? '' : ($v >= $target ? 'oms-ok' : 'oms-warn');
        $hours = fn ($h) => rtrim(rtrim(number_format((float) $h, 1), '0'), '.');
        $o = $config['outages'];
        $v = $config['visits'];
        $range = fn ($t) => $t['first'] ? $t['first']->format('j M').' – '.$t['last']->format('j M Y') : 'no real records';
    @endphp

    <div class="oms-stack">
        <div class="oms-box-demo">
            <strong>How to read this page.</strong>
            Network outages count as <em>restored within {{ $hours($o['window_hours']) }} h</em> when the fault was cleared within {{ $hours($o['window_hours']) }} clock hours
            of occurring (incident log). Site visits count as <em>completed within {{ $hours($v['window_hours']) }} business hours</em> on the ticket tracker's
            clock (07:00–18:00, every day). Targets: {{ $o['target'] }}% for outages, {{ $v['target'] }}% for site visits.
            The internal SLAs ({{ $hours($o['internal_hours']) }} h for outages, {{ $v['internal_label'] }} for visits) are shown alongside.
            Headline figures use <strong>real records only</strong>. Periods the source files do not cover are filled with generated
            <span class="oms-chip-demo">DEMO</span> data, numbered DEMO-…, which is never counted in the real figures.
        </div>

        <x-filament::section heading="Last 6 months — real records" compact>
            <div class="oms-stats oms-stats-tight">
                <div class="oms-stat">
                    <div class="oms-label">Outages restored within {{ $hours($o['window_hours']) }} h</div>
                    <div class="oms-stat-value {{ $tone($outages['real']['within_pct'], $o['target']) }}">{{ $pct($outages['real']['within_pct']) }}</div>
                    <div class="oms-target">Target {{ $o['target'] }}% · {{ number_format($outages['real']['within']) }} of {{ number_format($outages['real']['measured']) }} · {{ $range($outages) }}</div>
                </div>
                <div class="oms-stat">
                    <div class="oms-label">Outages closed (restoration recorded)</div>
                    <div class="oms-stat-value">{{ $pct($outages['real']['closed_pct']) }}</div>
                    <div class="oms-target">{{ number_format($outages['real']['closed']) }} of {{ number_format($outages['real']['total']) }} outages · {{ number_format($outages['real']['customers']) }} customers affected</div>
                </div>
                <div class="oms-stat">
                    <div class="oms-label">Site visits completed within {{ $hours($v['window_hours']) }} business h</div>
                    <div class="oms-stat-value {{ $tone($visits['real']['within_pct'], $v['target']) }}">{{ $pct($visits['real']['within_pct']) }}</div>
                    <div class="oms-target">Target {{ $v['target'] }}% · {{ number_format($visits['real']['within']) }} of {{ number_format($visits['real']['measured']) }} · {{ $range($visits) }}</div>
                </div>
                <div class="oms-stat">
                    <div class="oms-label">Site visits closed</div>
                    <div class="oms-stat-value">{{ $pct($visits['real']['closed_pct']) }}</div>
                    <div class="oms-target">{{ number_format($visits['real']['closed']) }} of {{ number_format($visits['real']['total']) }} visits · {{ number_format($visits['real']['open']) }} open</div>
                </div>
            </div>
            <div class="oms-label" style="margin-top:.75rem">
                Internal SLA on the same real records: outages {{ $pct($outages['real']['internal_pct']) }} within {{ $hours($o['internal_hours']) }} h ·
                site visits {{ $pct($visits['real']['internal_pct']) }} within {{ $v['internal_label'] }}.
            </div>
        </x-filament::section>

        @foreach ($charts as $chart)
            @livewire($chart)
        @endforeach

        <x-filament::section heading="Month by month" description="Real and demo rows are counted apart. Demo rows exist only where the source files have no records." compact>
            <div class="oms-scroll"><table class="oms-table">
                <thead>
                    <tr>
                        <th>Month</th><th></th>
                        <th>Outages</th><th>Closed</th><th>Within {{ $hours($o['window_hours']) }} h</th><th>Internal SLA</th>
                        <th>Site visits</th><th>Within {{ $hours($v['window_hours']) }} bh</th><th>Internal SLA</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($months as $m)
                    @foreach (['real', 'demo'] as $origin)
                        @php $out = $m['outages'][$origin]; $vis = $m['visits'][$origin]; @endphp
                        @continue($out['total'] === 0 && $vis['total'] === 0)
                        <tr @class(['oms-row-demo' => $origin === 'demo'])>
                            <td class="oms-value">{{ $m['label'] }}</td>
                            <td>@if ($origin === 'demo')<span class="oms-chip-demo">DEMO</span>@else<span class="oms-label">real</span>@endif</td>
                            <td>{{ $out['total'] ? number_format($out['total']) : '—' }}</td>
                            <td>{{ $out['total'] ? $pct($out['closed_pct']) : '—' }}</td>
                            <td class="{{ $tone($out['within_pct'], $o['target']) }}">{{ $out['total'] ? $pct($out['within_pct']) : '—' }}</td>
                            <td>{{ $out['total'] ? $pct($out['internal_pct']) : '—' }}</td>
                            <td>{{ $vis['total'] ? number_format($vis['total']) : '—' }}</td>
                            <td class="{{ $tone($vis['within_pct'], $v['target']) }}">{{ $vis['total'] ? $pct($vis['within_pct']) : '—' }}</td>
                            <td>{{ $vis['total'] ? $pct($vis['internal_pct']) : '—' }}</td>
                        </tr>
                    @endforeach
                @endforeach
                <tr>
                    <td class="oms-value">6 months</td><td><span class="oms-label">real</span></td>
                    <td class="oms-value">{{ number_format($outages['real']['total']) }}</td>
                    <td class="oms-value">{{ $pct($outages['real']['closed_pct']) }}</td>
                    <td class="oms-value {{ $tone($outages['real']['within_pct'], $o['target']) }}">{{ $pct($outages['real']['within_pct']) }}</td>
                    <td class="oms-value">{{ $pct($outages['real']['internal_pct']) }}</td>
                    <td class="oms-value">{{ number_format($visits['real']['total']) }}</td>
                    <td class="oms-value {{ $tone($visits['real']['within_pct'], $v['target']) }}">{{ $pct($visits['real']['within_pct']) }}</td>
                    <td class="oms-value">{{ $pct($visits['real']['internal_pct']) }}</td>
                </tr>
                </tbody>
            </table></div>
        </x-filament::section>

        <div class="oms-grid">
            <x-filament::section heading="Technicians" :description="number_format($technicians['total']).' technicians on the technician list · '.collect($technicians['engagement'])->map(fn ($n, $k) => number_format($n).' '.strtolower($k))->implode(' · ')" compact>
                <div class="oms-scroll"><table class="oms-table">
                    <thead><tr><th>Team</th><th>Type</th>@foreach (array_keys($technicians['roles']) as $role)<th>{{ str_replace(' Technician', '', $role) }}</th>@endforeach<th>Total</th></tr></thead>
                    <tbody>
                    @foreach ($technicians['teams'] as $t)
                        <tr>
                            <td class="oms-value">{{ $t['team'] }}</td>
                            <td><span class="oms-label">{{ $t['engagement'] }}</span></td>
                            @foreach ($t['roles'] as $n)<td>{{ $n ?: '—' }}</td>@endforeach
                            <td class="oms-value">{{ $t['total'] }}</td>
                        </tr>
                    @endforeach
                    <tr><td class="oms-value">All teams</td><td></td>@foreach ($technicians['roles'] as $n)<td class="oms-value">{{ $n }}</td>@endforeach<td class="oms-value">{{ $technicians['total'] }}</td></tr>
                    </tbody>
                </table></div>
            </x-filament::section>

            <x-filament::section heading="Field crews — real site visits" :description="'The 15 busiest crews in the ticket tracker, '.$range($visits)" compact>
                <div class="oms-scroll"><table class="oms-table">
                    <thead><tr><th>Crew</th><th>Visits</th><th>Within {{ $hours($v['window_hours']) }} bh</th><th>Internal SLA</th></tr></thead>
                    <tbody>
                    @forelse ($crews as $c)
                        <tr>
                            <td class="oms-value">{{ $c['crew'] }}</td>
                            <td>{{ number_format($c['visits']) }}</td>
                            <td class="{{ $tone($c['within'], $v['target']) }}">{{ $pct($c['within']) }}</td>
                            <td>{{ $pct($c['internal']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="oms-muted">No real site visits in the period.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </x-filament::section>
        </div>

        <div wire:poll.30s>
            <x-filament::section compact>
                <x-slot name="heading">Live site visits <span class="oms-chip-demo">DEMO</span></x-slot>
                <x-slot name="description">Generated every minute at the real average rate, each a copy of a real visit (issue, OLT, crew, time to complete). Not counted in the real figures.</x-slot>
                <div class="oms-stats">
                    <div class="oms-stat"><div class="oms-label">Raised today</div><div class="oms-stat-value">{{ number_format($live['raised_today']) }}</div></div>
                    <div class="oms-stat"><div class="oms-label">In the last hour</div><div class="oms-stat-value">{{ number_format($live['last_hour']) }}</div></div>
                    <div class="oms-stat"><div class="oms-label">Open now (last 7 days)</div><div class="oms-stat-value">{{ number_format($live['open']) }}</div></div>
                    <div class="oms-stat"><div class="oms-label">Closed today</div><div class="oms-stat-value">{{ number_format($live['closed_today']) }}</div></div>
                </div>
                <div class="oms-scroll"><table class="oms-table">
                    <thead><tr><th>Visit</th><th>Issue</th><th>OLT</th><th>Crew</th><th>Raised</th><th>State</th></tr></thead>
                    <tbody>
                    @forelse ($live['latest'] as $row)
                        <tr class="oms-row-demo">
                            <td class="oms-value">{{ $row['ticket_number'] }}</td>
                            <td>{{ \Illuminate\Support\Str::before($row['title'], ' — ') }}</td>
                            <td>{{ $row['location'] ?? '—' }}</td>
                            <td>{{ $row['crew'] ?? '—' }}</td>
                            <td>{{ \Illuminate\Support\Carbon::parse($row['start_time'])->format('d M H:i') }}</td>
                            <td>{{ $row['is_closed'] ? ($row['sla_state'] === 'not_applicable' ? 'Closed (times not measured)' : 'Closed — '.($row['state'] ?? '')) : 'Open' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="oms-muted">No demo visits yet — run <code>php artisan schedule:work</code>.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
