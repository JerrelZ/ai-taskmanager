{{--
    Inline label picker. Works in any Livewire component that exposes a
    `toggleLabel(int $id, int $labelId)` action and a `$this->labels` collection.
    Expects the task's `labels` relation to be eager-loaded.
--}}
<flux:dropdown wire:sort:ignore position="bottom" align="end">
    <button
        type="button"
        x-on:click.stop
        aria-label="{{ __('Labels aanpassen') }}"
        @class([
            'flex shrink-0 cursor-pointer items-center gap-1 rounded px-1 py-0.5 text-xs text-zinc-400 transition hover:bg-zinc-100 dark:hover:bg-zinc-700',
            'opacity-0 group-hover:opacity-100 focus-visible:opacity-100' => $task->labels->isEmpty(),
        ])
    >
        @if ($task->labels->isNotEmpty())
            @foreach ($task->labels->take(3) as $label)
                <span class="size-2 rounded-full bg-{{ $label->color }}-500"></span>
            @endforeach
        @else
            <flux:icon name="tag" variant="micro" />
        @endif
    </button>

    <flux:menu class="max-h-72 overflow-y-auto">
        @forelse ($this->labels as $label)
            <flux:menu.item x-on:click.stop wire:click="toggleLabel({{ $task->id }}, {{ $label->id }})" :icon="$task->labels->contains('id', $label->id) ? 'check' : null">
                <span class="flex items-center gap-2">
                    <span class="size-2 rounded-full bg-{{ $label->color }}-500"></span>
                    {{ $label->name }}
                </span>
            </flux:menu.item>
        @empty
            <flux:menu.item disabled>{{ __('Geen labels') }}</flux:menu.item>
        @endforelse
    </flux:menu>
</flux:dropdown>
