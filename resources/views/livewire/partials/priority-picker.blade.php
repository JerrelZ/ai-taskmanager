{{--
    Clickable priority picker. Renders the priority icon as a dropdown trigger so
    the priority can be changed inline. Works in any Livewire component that
    exposes a `setPriority(int $id, string $priority)` action (Board, Tickets).
--}}
<flux:dropdown wire:sort:ignore position="bottom" align="start">
    <button
        type="button"
        x-on:click.stop
        aria-label="{{ __('Prioriteit aanpassen') }}"
        class="-m-0.5 flex shrink-0 cursor-pointer items-center justify-center rounded p-0.5 transition hover:bg-zinc-100 dark:hover:bg-zinc-700"
    >
        <flux:icon :name="$task->priority->icon()" variant="micro" class="text-{{ $task->priority->color() }}-500" />
    </button>

    <flux:menu>
        @foreach (\App\Enums\TaskPriority::cases() as $priorityOption)
            <flux:menu.item
                x-on:click.stop
                wire:click="setPriority({{ $task->id }}, '{{ $priorityOption->value }}')"
                :icon="$task->priority === $priorityOption ? 'check' : null"
            >
                <span class="flex items-center gap-2">
                    <flux:icon :name="$priorityOption->icon()" variant="micro" class="text-{{ $priorityOption->color() }}-500" />
                    {{ $priorityOption->label() }}
                </span>
            </flux:menu.item>
        @endforeach
    </flux:menu>
</flux:dropdown>
