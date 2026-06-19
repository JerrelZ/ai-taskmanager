@php $progress = $task->subtaskProgress(); @endphp
<div
    wire:key="row-{{ $task->id }}"
    wire:sort:item="{{ $task->id }}"
    wire:click="openTask({{ $task->id }})"
    class="group flex cursor-pointer items-center gap-2 rounded-lg border border-transparent px-2 py-2 transition hover:border-zinc-200 hover:bg-zinc-50 sm:gap-3 sm:px-3 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/50"
>
    <button type="button" wire:sort:handle x-on:click.stop
        class="flex size-7 shrink-0 cursor-grab touch-none items-center justify-center rounded text-zinc-300 transition hover:text-zinc-500 active:cursor-grabbing lg:opacity-0 lg:group-hover:opacity-100 dark:text-zinc-600 dark:hover:text-zinc-300"
        aria-label="{{ __('Versleep') }}">
        <flux:icon name="bars-3" variant="micro" />
    </button>

    @include('livewire.partials.priority-picker', ['task' => $task])

    <span class="shrink-0 font-mono text-[0.7rem] tracking-tight text-zinc-400">{{ $task->identifier() }}</span>

    <p @class([
        'flex-1 truncate text-sm text-zinc-800 dark:text-zinc-100',
        'line-through opacity-60' => $task->isComplete(),
    ])>{{ $task->title }}</p>

    @if ($progress['total'] > 0)
        <span class="flex shrink-0 items-center gap-1 text-xs text-zinc-400">
            <flux:icon name="list-bullet" variant="micro" />
            {{ $progress['done'] }}/{{ $progress['total'] }}
        </span>
    @endif

    @if ($task->comments_count > 0)
        <span class="flex shrink-0 items-center gap-1 text-xs text-zinc-400">
            <flux:icon name="chat-bubble-oval-left" variant="micro" />
            {{ $task->comments_count }}
        </span>
    @endif

    @if ($task->due_date)
        <span @class([
            'flex shrink-0 items-center gap-1 text-xs text-zinc-400',
            'text-red-500' => $task->due_date->isPast() && ! $task->isComplete(),
        ])>
            <flux:icon name="calendar" variant="micro" />
            {{ $task->due_date->translatedFormat('j M') }}
        </span>
    @endif

    <div class="w-6 shrink-0">
        @if ($task->assignee)
            <flux:tooltip :content="$task->assignee->name">
                <flux:avatar size="xs" circle :name="$task->assignee->name" :initials="$task->assignee->initials()" />
            </flux:tooltip>
        @endif
    </div>
</div>
