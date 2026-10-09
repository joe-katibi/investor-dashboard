{{--
    Every ticket and site visit of one customer, newest first: what it was about, where it is now,
    how it ended. The number opens the ticket's own page (timeline, desks, attachments, SLA).
--}}
<div class="oms-stack">
    <div class="oms-stats oms-stats-tight">
        <div class="oms-stat"><div class="oms-label">Tickets and visits</div><div class="oms-stat-value">{{ $tickets->count() }}</div></div>
        <div class="oms-stat"><div class="oms-label">Open now</div><div class="oms-stat-value {{ $open > 0 ? 'oms-warn' : 'oms-ok' }}">{{ $open }}</div></div>
        <div class="oms-stat"><div class="oms-label">Last raised</div><div class="oms-stat-value">{{ $tickets->first()?->outage?->created_at?->format('d M Y') ?? '—' }}</div></div>
        <div class="oms-stat"><div class="oms-label">Position on the network</div><div class="oms-value">{{ $customer->topologyPath() ?: 'Not on file' }}</div></div>
    </div>

    @forelse ($tickets as $ticket)
        @php($outage = $ticket->outage)
        <x-filament::section compact>
            <x-slot name="heading">
                @if ($links[$ticket->id] ?? null)
                    <a href="{{ $links[$ticket->id] }}" target="_blank" rel="noopener" style="text-decoration: underline;">{{ $outage->ticket_number }}</a>
                @else
                    {{ $outage->ticket_number }}
                @endif
                — {{ $outage->title }}
            </x-slot>
            <x-slot name="description">
                {{ $outage->isSiteVisit() ? 'Site visit'.($ticket->visitType ? ' · '.$ticket->visitType->name : '') : 'Customer ticket' }}
                · {{ collect([$ticket->category?->name, $ticket->subcategory?->name])->filter()->implode(' › ') ?: 'No category' }}
            </x-slot>

            <div class="oms-grid oms-grid-4">
                <div><div class="oms-label">Status</div><div class="oms-value {{ $outage->is_closed ? 'oms-ok' : 'oms-warn' }}">{{ $outage->status?->name ?? '—' }}</div></div>
                <div><div class="oms-label">{{ $outage->is_closed ? 'Last desk' : 'Now with' }}</div><div class="oms-value">{{ collect([$outage->current_queue?->getLabel(), $outage->currentAssignee?->name])->filter()->implode(' · ') ?: '—' }}</div></div>
                <div><div class="oms-label">Raised</div><div class="oms-value">{{ $outage->created_at?->format('d M Y H:i') }}{{ $outage->creator ? ' · '.$outage->creator->name : '' }}</div></div>
                <div><div class="oms-label">Closed</div><div class="oms-value">{{ $outage->end_time?->format('d M Y H:i') ?? ($outage->is_closed ? 'Yes' : 'Still open') }}</div></div>
                <div><div class="oms-label">Priority</div><div class="oms-value">{{ $outage->priority?->name ?? '—' }}</div></div>
                <div><div class="oms-label">Calling number</div><div class="oms-value">{{ $ticket->calling_number ?: '—' }}</div></div>
                <div><div class="oms-label">Final reason</div><div class="oms-value">{{ $ticket->finalReason?->name ?? '—' }}</div></div>
                <div><div class="oms-label">Technician</div><div class="oms-value">{{ $outage->technician?->name ?? '—' }}</div></div>
            </div>

            @if (filled($outage->description))
                <div style="margin-top: .75rem;"><div class="oms-label">Reported</div><div class="oms-pre" style="white-space: pre-wrap;">{{ $outage->description }}</div></div>
            @endif
            @if (filled($ticket->resolution_notes))
                <div style="margin-top: .75rem;"><div class="oms-label">Work done / resolution</div><div class="oms-pre" style="white-space: pre-wrap;">{{ $ticket->resolution_notes }}</div></div>
            @endif
            @if (filled($outage->latest_update) && ! $outage->is_closed)
                <div style="margin-top: .75rem;"><div class="oms-label">Latest update</div><div class="oms-pre" style="white-space: pre-wrap;">{{ $outage->latest_update }}</div></div>
            @endif

            @if ($links[$ticket->id] ?? null)
                <div style="margin-top: .75rem;">
                    <x-filament::link :href="$links[$ticket->id]" target="_blank" icon="heroicon-o-arrow-top-right-on-square">
                        Open the full ticket — timeline, desks, attachments, SLA
                    </x-filament::link>
                </div>
            @endif
        </x-filament::section>
    @empty
        <div class="oms-muted">No ticket or site visit has been raised for this customer.</div>
    @endforelse
</div>
