@php $progress = $task->subtaskProgress(); @endphp
<div
    wire:key="card-{{ $task->id }}"
    wire:sort:item="{{ $task->id }}"
    wire:click="openTask({{ $task->id }})"
    @class([
        'group cursor-pointer rounded-lg border border-zinc-200 bg-white p-3 shadow-sm transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600',
        'opacity-60' => $task->isComplete(),
    ])
>
    <div class="mb-1 flex items-center justify-between gap-2">
        <span class="font-mono text-[0.7rem] tracking-tight text-zinc-400">{{ $task->identifier() }}</span>
        <button type="button" wire:sort:handle x-on:click.stop
            class="-me-1 flex size-7 shrink-0 cursor-grab touch-none items-center justify-center rounded text-zinc-300 transition hover:text-zinc-500 active:cursor-grabbing lg:opacity-0 lg:group-hover:opacity-100 dark:text-zinc-600 dark:hover:text-zinc-300"
            aria-label="{{ __('Versleep kaart') }}">
            <flux:icon name="bars-3" variant="micro" />
        </button>
    </div>
    <div class="flex items-start justify-between gap-2">
        <p @class([
            'text-sm font-medium text-zinc-800 dark:text-zinc-100',
            'line-through' => $task->status === \App\Enums\TaskStatus::Canceled,
        ])>{{ $task->title }}</p>
        @if (auth()->user()?->canCopyPrompt())
            <div wire:sort:ignore class="opacity-0 transition group-hover:opacity-100">
                <flux:tooltip :content="__('Kopieer als AI-prompt')">
                    <flux:button wire:click.stop="copyPrompt({{ $task->id }})" variant="subtle" size="xs" icon="clipboard-document" inset="top bottom" />
                </flux:tooltip>
            </div>
        @endif
    </div>

    <div class="mt-3 flex items-center gap-3 text-zinc-400">
        @if ($task->priority !== \App\Enums\TaskPriority::None)
            <flux:tooltip :content="$task->priority->label()">
                <flux:icon :name="$task->priority->icon()" variant="micro" class="text-{{ $task->priority->color() }}-500" />
            </flux:tooltip>
        @endif

        @if ($task->due_date)
            <span @class([
                'flex items-center gap-1 text-xs',
                'text-red-500' => $task->due_date->isPast() && ! $task->isComplete(),
            ])>
                <flux:icon name="calendar" variant="micro" />
                {{ $task->due_date->translatedFormat('j M') }}
            </span>
        @endif

        @if ($progress['total'] > 0)
            <span class="flex items-center gap-1 text-xs">
                <flux:icon name="list-bullet" variant="micro" />
                {{ $progress['done'] }}/{{ $progress['total'] }}
            </span>
        @endif

        @if ($task->comments_count > 0)
            <span class="flex items-center gap-1 text-xs">
                <flux:icon name="chat-bubble-oval-left" variant="micro" />
                {{ $task->comments_count }}
            </span>
        @endif

        <span class="flex-1"></span>

        @if ($task->assignee)
            <flux:tooltip :content="$task->assignee->name">
                <flux:avatar size="xs" circle :name="$task->assignee->name" :initials="$task->assignee->initials()" />
            </flux:tooltip>
        @endif
    </div>
</div>
