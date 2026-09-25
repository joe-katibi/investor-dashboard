<x-filament-widgets::widget>
    <div class="oms-grid" wire:poll.120s>
        <x-filament::section heading="FIFO queue health" icon="heroicon-o-queue-list" compact>
            <table class="oms-table">
                <thead><tr><th>Queue</th><th>Waiting</th><th>Awaiting ack</th><th>Accepted</th><th>Oldest waiting</th></tr></thead>
                <tbody>
                @foreach ($queues as $q)
                    <tr>
                        <td><x-filament::badge :color="$q['color']">{{ $q['label'] }}</x-filament::badge></td>
                        <td class="{{ $q['pending'] > 0 ? 'oms-warn' : '' }}">{{ $q['pending'] }}</td>
                        <td>{{ $q['assigned'] }}</td>
                        <td>{{ $q['accepted'] }}</td>
                        <td>{{ $q['oldest_minutes'] !== null ? $q['oldest_minutes'].' min' : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Team performance (period)" icon="heroicon-o-user-group" compact>
            <table class="oms-table">
                <thead><tr><th>Team</th><th>Assigned</th><th>Ack'd</th><th>Restored</th><th>Breached</th><th>Avg restore</th></tr></thead>
                <tbody>
                @forelse ($teams as $t)
                    <tr>
                        <td>{{ $t['name'] }}</td>
                        <td>{{ $t['assigned'] }}</td>
                        <td>{{ $t['accepted'] }}</td>
                        <td>{{ $t['completed'] }}</td>
                        <td class="{{ $t['breached'] > 0 ? 'oms-bad' : '' }}">{{ $t['breached'] }}</td>
                        <td>{{ $t['avg_restore'] !== null ? $t['avg_restore'].' min' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="oms-muted">No assignments in the period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Top impacted OLTs" icon="heroicon-o-cpu-chip" compact>
            <table class="oms-table">
                <thead><tr><th>OLT</th><th>FDT</th><th>Outages</th><th>Customers</th></tr></thead>
                <tbody>
                @forelse ($olts as $o)
                    <tr><td>{{ $o['olt'] }}</td><td>{{ $o['fdt'] }}</td><td>{{ $o['outages'] }}</td><td>{{ number_format($o['customers']) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="oms-muted">No OLT outages in the period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Top impacted services" icon="heroicon-o-server-stack" compact>
            <table class="oms-table">
                <thead><tr><th>Service</th><th>Outages</th><th>Customers</th><th>Active</th></tr></thead>
                <tbody>
                @forelse ($services as $s)
                    <tr><td>{{ $s['service'] }}</td><td>{{ $s['outages'] }}</td><td>{{ number_format($s['customers']) }}</td><td>{{ $s['active'] }}</td></tr>
                @empty
                    <tr><td colspan="4" class="oms-muted">No service impact in the period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
