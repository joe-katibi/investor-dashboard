<x-filament-panels::page>
    @php
        $tile = fn (string $label, $value, ?string $hint = null, string $tone = '', ?string $url = null) => compact('label', 'value', 'hint', 'tone', 'url');

        $live = [
            $tile('Open outages', $k['open'], "{$k['active']} being worked", $k['open'] > 0 ? 'oms-warn' : 'oms-ok', $tab('active')),
            $tile('Critical', $k['critical'], "Major {$k['major']} · Minor {$k['minor']}", $k['critical'] > 0 ? 'oms-bad' : '', $tab('critical')),
            $tile('Resolved — pending NOC confirmation', $k['pending_noc'], 'Waiting for NOC verification', $k['pending_noc'] > 0 ? 'oms-warn' : '', $tab('pending')),
            $tile('SLA breached', $k['sla_breached'], "{$k['sla_at_risk']} approaching SLA", $k['sla_breached'] > 0 ? 'oms-bad' : ($k['sla_at_risk'] > 0 ? 'oms-warn' : ''), $tab('sla')),
            $tile('eNodeB outages', $k['enodeb'], "{$k['sites_down']} sites · {$k['cells_down']} cells down", '', $tab('enodeb')),
            $tile('Transmission outages', $k['transmission'], 'Nodes / links', '', $tab('transmission')),
            $tile('Power outages', $k['power'], 'Handled by vendors', '', $tab('power')),
            $tile('Open vendor tickets', $k['with_vendor'], 'With external vendors', '', $tab('vendor')),
        ];

        $period = [
            $tile('Total 4G outages', $k['total'], 'Started in the period'),
            $tile('Closed outages', $k['closed'], 'Verified and closed by NOC', '', $tab('closed')),
            $tile('MTTR', $human($k['mttr']), 'Outage start → end'),
            $tile('SLA compliance', $k['sla_compliance'] !== null ? $k['sla_compliance'].'%' : '—', 'Closed within SLA', $k['sla_compliance'] !== null && $k['sla_compliance'] < 90 ? 'oms-warn' : ''),
            $tile('Average restoration time', $human($k['avg_restoration']), 'Outage start → service restored'),
            $tile('Average resolution time', $human($k['avg_resolution']), 'Ticket created → closed'),
        ];
    @endphp

    <div wire:poll.60s class="oms-stack">
        @foreach (['Live' => $live, 'Period' => $period] as $heading => $tiles)
            <x-filament::section :heading="$heading" compact>
                <div class="oms-stats oms-stats-tight">
                    @foreach ($tiles as $t)
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
        @endforeach

        <x-filament::section heading="Open 4G outages — closest to SLA first" icon="heroicon-o-signal" compact>
            <div class="oms-scroll"><table class="oms-table">
                <thead><tr><th>Ticket</th><th>Category</th><th>Severity</th><th>Site(s) / node(s)</th><th>Status</th><th>With</th><th>SLA</th><th>Open for</th></tr></thead>
                <tbody>
                @forelse ($open as $o)
                    @php $sla = $o->currentSlaState(); @endphp
                    <tr>
                        <td>
                            <a class="underline oms-value" href="{{ \App\Filament\Resources\MobilityOutages\MobilityOutageResource::getUrl('view', ['record' => $o]) }}" wire:navigate>{{ $o->ticket_number }}</a>
                            <div class="oms-muted">{{ \Illuminate\Support\Str::limit($o->title, 50) }}</div>
                        </td>
                        <td>{{ $o->domainLabel() ?? '—' }}</td>
                        <td><x-filament::badge :color="$o->priority?->color ?? 'gray'">{{ $o->priority?->name ?? '—' }}</x-filament::badge></td>
                        <td>{{ $o->sitesLabel(3) ?: ($o->nodesLabel(3) ?: ($o->location ?: '—')) }}</td>
                        <td><x-filament::badge :color="$o->status?->color ?? 'gray'">{{ $o->status?->name }}</x-filament::badge></td>
                        <td>{{ \App\Filament\Resources\MobilityOutages\MobilityOutageResource::owner($o) ?? '4G NOC' }}</td>
                        <td class="{{ $sla === \App\Enums\SlaState::Breached ? 'oms-bad' : ($sla === \App\Enums\SlaState::AtRisk ? 'oms-warn' : '') }}">
                            {{ $sla?->getLabel() ?? '—' }}
                            @if ($o->sla_due_at)<div class="oms-muted">due {{ $o->sla_due_at->diffForHumans(short: true) }}</div>@endif
                        </td>
                        <td>{{ $o->elapsedForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="oms-muted">No open 4G outages.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </x-filament::section>

        <div class="oms-grid">
            <x-filament::section heading="Vendor performance" icon="heroicon-o-building-office-2" compact>
                <div class="oms-scroll"><table class="oms-table">
                    <thead><tr><th>Vendor</th><th>Open</th><th>Tickets</th><th>Escalated</th><th>Avg response</th><th>Avg resolution</th><th>SLA</th></tr></thead>
                    <tbody>
                    @forelse ($vendors as $v)
                        <tr>
                            <td>{{ $v['vendor'] }}</td>
                            <td class="{{ $v['open'] > 0 ? 'oms-warn' : '' }}">{{ $v['open'] }}</td>
                            <td>{{ $v['tickets'] }}</td>
                            <td class="{{ $v['escalated'] > 0 ? 'oms-bad' : '' }}">{{ $v['escalated'] }}</td>
                            <td>{{ $human($v['avg_response']) }}</td>
                            <td>{{ $human($v['avg_resolution']) }}</td>
                            <td>{{ $v['sla_compliance'] !== null ? $v['sla_compliance'].'%' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="oms-muted">No vendors yet.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </x-filament::section>

            <x-filament::section heading="Engineers / technicians" icon="heroicon-o-wrench-screwdriver" compact>
                <div class="oms-scroll"><table class="oms-table">
                    <thead><tr><th>Engineer</th><th>Assigned</th><th>Open</th><th>Resolved</th><th>Avg resolution</th><th>SLA</th></tr></thead>
                    <tbody>
                    @forelse ($engineers as $e)
                        <tr>
                            <td>{{ $e['engineer'] }}</td>
                            <td>{{ $e['assigned'] }}</td>
                            <td class="{{ $e['open'] > 0 ? 'oms-warn' : '' }}">{{ $e['open'] }}</td>
                            <td>{{ $e['resolved'] }}</td>
                            <td>{{ $human($e['avg_resolution']) }}</td>
                            <td>{{ $e['sla_compliance'] !== null ? $e['sla_compliance'].'%' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="oms-muted">No tickets assigned to engineers in the period.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
