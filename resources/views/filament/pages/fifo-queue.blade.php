<x-filament-panels::page>
    @php($counts = $this->getQueueCounts())
    <x-filament::tabs label="Queues">
        @foreach ($this->visibleQueues() as $type)
            <x-filament::tabs.item
                :active="$this->queueType() === $type"
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
