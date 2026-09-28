<x-filament-panels::page>
    <form wire:submit="submit" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap gap-3">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                Send request to NOC
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
