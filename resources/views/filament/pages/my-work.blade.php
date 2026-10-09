<x-filament-panels::page>
    {{-- Looks for new work by itself; keep-alive: also while the browser tab is not in front. --}}
    <div wire:poll.keep-alive.{{ \App\Filament\Pages\MyWork::REFRESH_SECONDS }}s="checkForNewWork">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
