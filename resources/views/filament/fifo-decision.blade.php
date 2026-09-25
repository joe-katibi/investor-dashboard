@php
    /** @var \App\Models\OutageAssignment $assignment */
    $meta = $assignment->decision_meta ?? [];
@endphp
<div class="oms-stack">
    <div class="oms-grid-4">
        <div><div class="oms-label">Assigned to</div><div class="oms-value">{{ $assignment->user?->name }}</div></div>
        <div><div class="oms-label">Method</div><div class="oms-value">{{ $assignment->assignment_type->getLabel() }}</div></div>
        <div><div class="oms-label">Queue position</div><div class="oms-value">{{ $assignment->queue_position ?? '—' }}</div></div>
        <div><div class="oms-label">Priority at assignment</div><div class="oms-value">{{ $assignment->priority_name ?? '—' }}</div></div>
        <div><div class="oms-label">FIFO queue time</div><div class="oms-value">{{ $assignment->queued_at?->format('Y-m-d H:i:s.v') }}</div></div>
        <div><div class="oms-label">Assigned at</div><div class="oms-value">{{ $assignment->assigned_at?->format('Y-m-d H:i:s') }}</div></div>
        <div><div class="oms-label">Acknowledge by</div><div class="oms-value">{{ $assignment->ack_due_at?->format('H:i') ?? 'No timeout' }}</div></div>
        <div><div class="oms-label">Assigned by</div><div class="oms-value">{{ $assignment->assignedBy?->name ?? 'FIFO engine' }}</div></div>
    </div>

    @if ($assignment->assignment_reason)
        <div class="oms-box">{{ $assignment->assignment_reason }}</div>
    @endif

    @if (! empty($meta['failed_checks']))
        <div class="oms-box oms-box-danger">
            <div class="oms-value">Override — rules the user did not satisfy:</div>
            <ul class="oms-list">
                @foreach ($meta['failed_checks'] as $failed)
                    <li>{{ $failed }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! empty($meta['eligible_ranking']))
        <div>
            <div class="oms-value">Eligible users at decision time ({{ $meta['eligible_count'] ?? count($meta['eligible_ranking']) }} of {{ $meta['candidates_evaluated'] ?? '?' }} evaluated)</div>
            <p class="oms-muted">{{ $meta['selection_order'] ?? '' }}</p>
            <table class="oms-table">
                <thead><tr><th>Rank</th><th>User</th><th>Active tickets</th><th>Last assigned</th></tr></thead>
                <tbody>
                    @foreach ($meta['eligible_ranking'] as $row)
                        <tr @class(['oms-highlight' => $row['user_id'] === $assignment->user_id])>
                            <td>{{ $row['rank'] }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['workload'] }}</td>
                            <td>{{ $row['last_assigned_at'] ? \Illuminate\Support\Carbon::parse($row['last_assigned_at'])->format('d M H:i:s') : 'Never' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if (! empty($meta['excluded_summary']))
        <div>
            <div class="oms-value">Exclusions by rule</div>
            <div class="oms-chips">
                @foreach ($meta['excluded_summary'] as $rule => $count)
                    <span class="oms-chip">{{ str($rule)->replace('_', ' ')->title() }}: {{ $count }}</span>
                @endforeach
            </div>
        </div>
    @endif

    @if (! empty($meta['checks']))
        <div>
            <div class="oms-value">Eligibility checks for {{ $assignment->user?->name }}</div>
            <ul>
                @foreach ($meta['checks'] as $check)
                    <li>
                        <span class="{{ $check['passed'] ? 'oms-check-pass' : 'oms-check-fail' }}">{{ $check['passed'] ? '✔' : '✘' }}</span>
                        <strong>{{ $check['label'] }}</strong> — {{ $check['detail'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! empty($meta['rules']))
        <details>
            <summary class="oms-muted" style="cursor: pointer">FIFO configuration applied</summary>
            <pre class="oms-pre">{{ json_encode($meta['rules'], JSON_PRETTY_PRINT) }}</pre>
        </details>
    @endif
</div>
