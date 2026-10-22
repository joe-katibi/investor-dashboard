{{--
    What the ONU export syncs (oms:sync-onu-export) changed on one customer, newest first: the OLT, FDT, slot, port,
    ONU type, physical address… with the value before and after, and the dump it came from.
--}}
@php($fields = \App\Models\CustomerOnuChange::FIELDS)
@if ($changes->isEmpty())
    <p class="oms-muted">No change from an ONU export yet.</p>
@else
    <div class="oms-scroll"><table class="oms-table">
        <thead><tr><th>When</th><th>What</th><th>Before</th><th>After</th><th>Export</th></tr></thead>
        <tbody>
        @foreach ($changes as $change)
            <tr>
                <td>{{ $change->created_at?->format('d M Y H:i') }}</td>
                <td>{{ $fields[$change->field] ?? $change->field }}</td>
                <td>{{ $change->field === 'created' ? '' : ($change->old_value ?? '—') }}</td>
                <td>{{ $change->new_value ?? '—' }}</td>
                <td>{{ $change->run?->dumped_at?->format('d M Y H:i') ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
@endif
