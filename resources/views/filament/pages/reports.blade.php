<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @php($report = $this->getReport())

    @if ($report)
        <x-filament::section :heading="$report->title" :description="$report->description">
            @if ($report->summary)
                <div class="oms-stats">
                    @foreach ($report->summary as $label => $value)
                        <div class="oms-stat">
                            <div class="oms-label">{{ $label }}</div>
                            <div class="oms-stat-value">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            @php($rows = $report->rowsArray($this->screenLimit()))
            <div style="overflow-x: auto" wire:loading.class="opacity-50">
                <table class="oms-table">
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
                            <tr><td colspan="{{ count($report->columns) }}" class="oms-muted">No records for the selected filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (count($rows) >= $this->screenLimit())
                <p class="oms-muted" style="margin-top: .75rem">
                    Showing the first {{ $this->screenLimit() }} rows. Export to Excel or CSV for the complete data set.
                </p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
