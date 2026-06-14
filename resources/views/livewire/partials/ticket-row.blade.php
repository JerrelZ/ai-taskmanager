@php
    $progress = $task->subtaskProgress();
    $stale = $task->isStale();
    $touched = $task->lastTouchedAt();
@endphp
<div
    wire:key="ticket-{{ $task->id }}"
    wire:sort:item="{{ $task->id }}"
    @class([
        'group flex items-center gap-3 border-s-4 bg-white px-3 py-2.5 transition hover:bg-zinc-50 dark:bg-zinc-900 dark:hover:bg-zinc-800/60',
        'border-'.$task->project->color.'-500',
    ])
>
    {{-- Drag handle --}}
    <div wire:sort:handle class="hidden cursor-grab text-zinc-300 opacity-0 transition group-hover:opacity-100 sm:block dark:text-zinc-600" title="{{ __('Sleep om prioriteit te bepalen') }}">
        <flux:icon name="bars-2" variant="micro" />
    </div>

    {{-- Priority --}}
    @if ($task->priority !== \App\Enums\TaskPriority::None)
        <flux:tooltip :content="$task->priority->label()">
            <flux:icon :name="$task->priority->icon()" variant="micro" class="shrink-0 text-{{ $task->priority->color() }}-500" />
        </flux:tooltip>
    @else
        <span class="w-3.5 shrink-0"></span>
    @endif

    {{-- Title + project --}}
    <button type="button" wire:click="openTask({{ $task->id }})" class="flex min-w-0 flex-1 flex-col items-start text-start">
        <span @class([
            'truncate text-sm font-medium text-zinc-800 dark:text-zinc-100',
            'line-through opacity-60' => $task->isComplete(),
        ])>{{ $task->title }}</span>
        <span class="flex items-center gap-1.5 text-xs text-zinc-400">
            <span class="font-mono text-[0.7rem] tracking-tight text-zinc-400">{{ $task->identifier() }}</span>
            <span class="size-1.5 rounded-full bg-{{ $task->project->color }}-500"></span>
            {{ $task->project->name }}
        </span>
    </button>

    {{-- Stale --}}
    @if ($stale)
        <flux:tooltip :content="__('Niet bijgewerkt sinds').' '.$touched?->translatedFormat('j M Y')">
            <flux:badge color="amber" size="sm" icon="clock">{{ $touched?->diffForHumans(short: true) }}</flux:badge>
        </flux:tooltip>
    @endif

    {{-- Subtasks / comments --}}
    <div class="hidden items-center gap-3 text-xs text-zinc-400 sm:flex">
        @if ($progress['total'] > 0)
            <span class="flex items-center gap-1"><flux:icon name="list-bullet" variant="micro" />{{ $progress['done'] }}/{{ $progress['total'] }}</span>
        @endif
        @if ($task->comments_count > 0)
            <span class="flex items-center gap-1"><flux:icon name="chat-bubble-oval-left" variant="micro" />{{ $task->comments_count }}</span>
        @endif
    </div>

    {{-- Due --}}
    @if ($task->due_date)
        <span @class([
            'hidden shrink-0 items-center gap-1 text-xs text-zinc-400 sm:flex',
            '!text-red-500' => $task->due_date->isPast() && ! $task->isComplete(),
        ])>
            <flux:icon name="calendar" variant="micro" />{{ $task->due_date->translatedFormat('j M') }}
        </span>
    @endif

    {{-- Assignee --}}
    <div class="w-6 shrink-0">
        @if ($task->assignee)
            <flux:tooltip :content="$task->assignee->name">
                <flux:avatar size="xs" circle :name="$task->assignee->name" :initials="$task->assignee->initials()" />
            </flux:tooltip>
        @endif
    </div>

    {{-- Actions --}}
    <div wire:sort:ignore class="hidden shrink-0 items-center gap-0.5 opacity-0 transition group-hover:opacity-100 sm:flex">
        @if ($stale)
            <flux:tooltip :content="__('Markeer als bijgewerkt')">
                <flux:button wire:click="markReviewed({{ $task->id }})" variant="subtle" size="xs" icon="check" inset="top bottom" />
            </flux:tooltip>
        @endif
        <flux:tooltip :content="__('Kopieer als AI-prompt')">
            <flux:button wire:click="copyPrompt({{ $task->id }})" variant="subtle" size="xs" icon="clipboard-document" inset="top bottom" />
        </flux:tooltip>
    </div>
</div>
