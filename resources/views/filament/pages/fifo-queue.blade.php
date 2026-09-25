<x-filament-panels::page>
    @php($counts = $this->getQueueCounts())
    <x-filament::tabs label="Queues">
        @foreach (\App\Enums\QueueType::cases() as $type)
            <x-filament::tabs.item
                :active="$queue === $type->value"
                wire:click="$set('queue', '{{ $type->value }}')"
                :badge="$counts[$type->value] ?? 0"
                :badge-color="($counts[$type->value] ?? 0) > 0 ? 'warning' : 'gray'"
            >
                {{ $type->getLabel() }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
