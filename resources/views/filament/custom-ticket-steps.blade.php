@php
    /** @var \App\Models\Outage $record */
    $record = $getRecord();
    $custom = $record->customTicket;
    $steps = $custom?->steps ?? [];
    // Every stay at a step, oldest first: who had it, from when to when, against the step's target.
    $stays = $record->queueEntries()->where('queue_type', \App\Enums\QueueType::CustomStep->value)
        ->with(['assignments' => fn ($q) => $q->orderBy('id')->with('user:id,name')])->orderBy('id')->get();
    $now = now();
@endphp

<div class="oms-stack">
    <div class="oms-chips">
        @foreach ($steps as $i => $step)
            @php
                $state = $record->is_closed || $i < $custom->step_index ? 'done' : ($i === $custom->step_index ? 'now' : 'next');
            @endphp
            <span class="oms-chip {{ $state === 'done' ? 'oms-ok' : ($state === 'now' ? 'oms-warn oms-value' : 'oms-muted') }}">
                {{ $i + 1 }}. {{ $step['name'] }}@if ($state === 'now') — here now @endif
            </span>
        @endforeach
    </div>

    @if (! $record->is_closed && ($current = $custom?->step()) && filled($current['instructions'] ?? null))
        <div class="oms-box"><span class="oms-label">At this step</span> {{ $current['instructions'] }}</div>
    @endif

    <div class="oms-scroll"><table class="oms-table">
        <thead><tr><th>Step</th><th>Team</th><th>With</th><th>From</th><th>Until</th><th>Time at the step</th><th>Step target</th><th>What happened</th></tr></thead>
        <tbody>
        @forelse ($stays as $stay)
            @php
                $end = $stay->completed_at ?? ($record->is_closed ? ($record->resolved_at ?? $now) : $now);
                $minutes = max(0, intdiv($end->getTimestamp() - $stay->queued_at->getTimestamp(), 60));
                $late = $stay->sla_due_at && $end->greaterThan($stay->sla_due_at);
                $people = $stay->assignments->map(fn ($a) => $a->user?->name)->filter()->unique()->implode(' → ');
                $team = collect($steps)->firstWhere('name', $stay->step_name)['team'] ?? null;
            @endphp
            <tr>
                <td class="oms-value">{{ $stay->label() }}</td>
                <td>{{ $team ?? '—' }}</td>
                <td>{{ $people !== '' ? $people : 'Nobody yet' }}</td>
                <td>{{ $stay->queued_at->format('d M H:i') }}</td>
                <td>{{ $stay->completed_at?->format('d M H:i') ?? '—' }}</td>
                <td>{{ $minutes >= 120 ? round($minutes / 60, 1).' h' : $minutes.' min' }}</td>
                <td class="{{ $stay->sla_due_at ? ($late ? 'oms-bad' : 'oms-ok') : 'oms-muted' }}">
                    {{ $stay->sla_due_at ? $stay->sla_minutes.' min — '.($late ? 'breached' : ($stay->completed_at ? 'kept' : \App\Services\Sla\DeskSla::describe($stay->sla_due_at))) : 'None' }}
                </td>
                <td>{{ $stay->completed_at ? \Illuminate\Support\Str::limit((string) ($stay->assignments->last()?->release_reason ?? $stay->status->getLabel()), 140) : 'Waiting here' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="oms-muted">The ticket has not reached a step yet.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
