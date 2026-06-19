{{--
    Inline status picker. Works in any Livewire component that exposes a
    `setStatus(int $id, string $status)` action (Board, Tickets).
--}}
<flux:dropdown wire:sort:ignore position="bottom" align="start">
    <flux:badge as="button" type="button" x-on:click.stop :color="$task->status->color()" size="sm" rounded class="cursor-pointer">
        {{ $task->status->label() }}
    </flux:badge>

    <flux:menu>
        @foreach (\App\Enums\TaskStatus::cases() as $statusOption)
            <flux:menu.item
                x-on:click.stop
                wire:click="setStatus({{ $task->id }}, '{{ $statusOption->value }}')"
                :icon="$task->status === $statusOption ? 'check' : null"
            >
                {{ $statusOption->label() }}
            </flux:menu.item>
        @endforeach
    </flux:menu>
</flux:dropdown>
