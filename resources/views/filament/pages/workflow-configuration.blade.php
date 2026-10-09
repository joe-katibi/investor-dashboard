<x-filament-panels::page>
    <x-filament::tabs label="Flows">
        @foreach ($flows as $key => $item)
            <x-filament::tabs.item :active="$flow === $key" wire:click="$set('flow', '{{ $key }}')">
                {{ $item['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    <div class="oms-stack">
        <x-filament::section :heading="$flows[$flow]['label'].' — the steps'" :description="$flows[$flow]['description']" compact>
            <div class="oms-scroll"><table class="oms-table">
                <thead><tr>
                    <th>#</th><th>Step</th><th>Who works it</th><th>How it is given</th><th>Time to accept</th><th>Desk SLA target</th><th>Notifications</th><th></th>
                </tr></thead>
                <tbody>
                @foreach ($steps as $step)
                    <tr>
                        <td class="oms-value">{{ $step['kind'] === 'step' ? $step['number'] : '' }}</td>
                        <td>
                            <span class="oms-value">{{ $step['name'] }}</span>
                            @if ($step['kind'] === 'alongside')
                                <x-filament::badge color="info" size="sm">alongside</x-filament::badge>
                            @elseif ($step['kind'] === 'branch')
                                <x-filament::badge color="gray" size="sm">only when needed</x-filament::badge>
                            @endif
                            <div class="oms-muted">{{ $step['what'] }}</div>
                        </td>
                        <td>
                            @if ($step['roles'] !== [])
                                {{ implode(', ', array_slice($step['roles'], 0, 6)) }}@if (count($step['roles']) > 6) +{{ count($step['roles']) - 6 }} more @endif
                                <div class="oms-muted">{{ $step['people'] }} active {{ \Illuminate\Support\Str::plural('person', $step['people']) }} · permission “{{ $step['permission_label'] }}”</div>
                            @else
                                <span class="oms-warn">No role holds “{{ $step['permission_label'] }}”</span>
                            @endif
                            @if ($step['department'] || $step['team'])
                                <div class="oms-muted">Only {{ collect([$step['department'], $step['team']])->filter()->implode(' / ') }}</div>
                            @endif
                        </td>
                        @if ($step['desk'])
                            <td>{{ $step['mode'] }}@if ($step['more_queues'] > 0)<div class="oms-muted">+{{ $step['more_queues'] }} more specific {{ \Illuminate\Support\Str::plural('queue', $step['more_queues']) }} (FIFO Queues)</div>@endif</td>
                            <td>{{ $step['ack'] > 0 ? $step['ack'].' min' : 'No limit' }}<div class="oms-muted">escalated after {{ $step['attempts'] }} rejections / time-outs</div></td>
                            <td class="{{ $step['target'] > 0 ? 'oms-value' : 'oms-muted' }}">{{ $step['target'] > 0 ? $step['target'].' min' : 'None' }}</td>
                        @else
                            <td class="oms-muted" colspan="3">Not a queue: done by whoever holds the permission.</td>
                        @endif
                        <td>
                            @forelse ($step['events'] as $event)
                                <div class="{{ $event['rules'] === 0 ? 'oms-muted' : '' }}">{{ $event['label'] }} · {{ $event['rules'] }} {{ \Illuminate\Support\Str::plural('rule', $event['rules']) }}</div>
                            @empty
                                <span class="oms-muted">—</span>
                            @endforelse
                        </td>
                        <td>
                            @if ($step['desk'])
                                {{ ($this->editStepAction)(['desk' => $step['desk']]) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="oms-muted">
                “Who works it” follows the roles: give or take the permission under Roles &amp; Permissions. Notification recipients are set per event under Notification Rules.
                The order of the steps of a flow is fixed in this version; what can be added or dropped is under “Switches” below.
            </div>
        </x-filament::section>

        <x-filament::section heading="Switches of this flow" description="They add or drop a step, or change how one behaves." compact>
            @if ($switches === [])
                <div class="oms-muted">This flow has no switches.</div>
            @else
                <div class="oms-scroll"><table class="oms-table">
                    <tbody>
                    @foreach ($switches as $switch)
                        <tr>
                            <td>{{ $switch['label'] }}@if ($switch['help'])<div class="oms-muted">{{ $switch['help'] }}</div>@endif</td>
                            <td class="oms-value {{ $switch['type'] === 'bool' ? ($switch['value'] ? 'oms-ok' : 'oms-warn') : '' }}">
                                {{ $switch['type'] === 'bool' ? ($switch['value'] ? 'Yes' : 'No') : $switch['value'] }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <div>{{ $this->editSwitchesAction }}</div>
            @endif

            @if ($links !== [])
                <div class="oms-muted">
                    Also part of this flow:
                    @foreach ($links as $label => $url)
                        <a class="underline" href="{{ $url }}" wire:navigate>{{ $label }}</a>@if (! $loop->last) · @endif
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Latest changes" description="A change applies to tickets reaching a step from then on. Tickets already past it keep what was in force: their history, queue entries and SLA targets are never rewritten." compact collapsible>
            <div class="oms-scroll"><table class="oms-table">
                <thead><tr><th>When</th><th>By</th><th>What</th><th>Change</th></tr></thead>
                <tbody>
                @forelse ($history as $entry)
                    <tr>
                        <td>{{ $entry['at'] }}</td>
                        <td>{{ $entry['by'] }}</td>
                        <td>{{ $entry['what'] }}</td>
                        <td>@foreach ($entry['changes'] as $line)<div>{{ \Illuminate\Support\Str::limit($line, 160) }}</div>@endforeach</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="oms-muted">Nothing has been changed yet.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
