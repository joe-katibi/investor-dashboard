@php
    /** @var \App\Models\OutageQueueEntry $entry */
    /** @var \Illuminate\Support\Collection $rows */
    $ruleKeys = $rows->flatMap(fn ($row) => array_keys($row['checks']))->unique()->values();
    $labels = $rows->first()['checks'] ?? [];
@endphp
<div class="oms-stack">
    <p class="oms-muted">
        Queue time {{ $entry->queued_at->format('Y-m-d H:i:s.v') }} · priority rank {{ $entry->priority_rank }} ·
        attempts {{ $entry->attempts }}. Eligible users are listed first in the order FIFO would pick them
        (longest since last assignment first).
    </p>
    <div style="overflow-x: auto">
        <table class="oms-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Eligible</th>
                    @foreach ($ruleKeys as $key)
                        <th>{{ $labels[$key]['label'] ?? str($key)->headline() }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $i => $row)
                    <tr @class(['oms-highlight' => $i === 0 && $row['eligible']])>
                        <td>{{ $row['eligible'] ? $i + 1 : '—' }}</td>
                        <td>{{ $row['user']->name }}</td>
                        <td class="{{ $row['eligible'] ? 'oms-ok' : 'oms-bad' }}">{{ $row['eligible'] ? 'Yes' : 'No' }}</td>
                        @foreach ($ruleKeys as $key)
                            @php($check = $row['checks'][$key] ?? null)
                            <td title="{{ $check['detail'] ?? '' }}" class="{{ $check ? ($check['passed'] ? 'oms-check-pass' : 'oms-check-fail') : 'oms-muted' }}">
                                {{ $check ? ($check['passed'] ? '✔' : '✘') : '·' }}
                                @if ($check && ! $check['passed'])
                                    <div class="oms-muted" style="font-size: .7rem">{{ $check['detail'] }}</div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ 3 + $ruleKeys->count() }}" class="oms-muted">No users hold the permission for this queue.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
