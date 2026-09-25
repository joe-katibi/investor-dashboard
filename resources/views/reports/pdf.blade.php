<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111827; }
        h1 { font-size: 14px; margin: 0 0 4px; }
        .meta { color: #6b7280; margin-bottom: 8px; }
        .summary { margin-bottom: 8px; }
        .summary span { display: inline-block; margin-right: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #b45309; color: #fff; text-align: left; padding: 3px; }
        td { border-bottom: 1px solid #e5e7eb; padding: 3px; vertical-align: top; }
        tr:nth-child(even) td { background: #f9fafb; }
    </style>
</head>
<body>
    <h1>{{ $report->title }}</h1>
    <div class="meta">
        {{ config('app.name') }} · Generated {{ now()->format('Y-m-d H:i') }}
        @if (! empty($filters['from']) || ! empty($filters['to']))
            · Period {{ $filters['from'] ?? '…' }} → {{ $filters['to'] ?? '…' }}
        @endif
    </div>
    @if ($report->summary)
        <div class="summary">
            @foreach ($report->summary as $label => $value)
                <span><strong>{{ $label }}:</strong> {{ $value }}</span>
            @endforeach
        </div>
    @endif
    <table>
        <thead>
            <tr>
                @foreach ($report->columns as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach (array_keys($report->columns) as $key)
                        <td>{{ $row[$key] ?? '' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($report->columns) }}">No records for the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($truncated)
        <p class="meta">PDF limited to {{ \App\Services\Reports\ReportExporter::PDF_ROW_LIMIT }} rows — use the Excel or CSV export for the full data set.</p>
    @endif
</body>
</html>
