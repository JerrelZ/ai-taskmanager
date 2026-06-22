@php
    $progress = $task->subtaskProgress();
    $stale = $task->isStale();
    $touched = $task->lastTouchedAt();
    $isTeam = auth()->user()?->isTeam();
@endphp
<div
    wire:key="ticket-{{ $task->id }}"
    wire:sort:item="{{ $task->id }}"
    @class([
        'group flex items-center gap-3 border-s-4 bg-white px-3 py-2.5 transition hover:bg-zinc-50 dark:bg-zinc-900 dark:hover:bg-zinc-800/60',
        'border-'.$task->project->color.'-500',
        'bg-brand-50/60 dark:bg-brand-950/30' => in_array($task->id, $selectedTickets),
    ])
>
    {{-- Bulk select (team only) --}}
    @if ($isTeam)
        <div wire:sort:ignore class="shrink-0">
            <flux:checkbox wire:model.live="selectedTickets" value="{{ $task->id }}" x-on:click.stop />
        </div>
    @endif

    {{-- Drag handle --}}
    <div wire:sort:handle class="hidden cursor-grab text-zinc-300 opacity-0 transition group-hover:opacity-100 sm:block dark:text-zinc-600" title="{{ __('Sleep om prioriteit te bepalen') }}">
        <flux:icon name="bars-2" variant="micro" />
    </div>

    {{-- Priority (click to change) --}}
    @include('livewire.partials.priority-picker', ['task' => $task])

    {{-- Status (click to change) --}}
    <div class="hidden shrink-0 sm:block">
        @include('livewire.partials.status-picker', ['task' => $task])
    </div>

    {{-- Title + project --}}
    <button type="button" wire:click="openTask({{ $task->id }})" class="flex min-w-0 flex-1 flex-col items-start text-start">
        <span @class([
            'truncate text-sm font-medium text-zinc-800 dark:text-zinc-100',
            'line-through opacity-60' => $task->isComplete(),
        ])>{{ $task->title }}</span>
        <span class="flex items-center gap-1.5 text-xs text-zinc-400">
            <span class="font-mono text-[0.7rem] tracking-tight text-zinc-400">{{ $task->identifier() }}</span>
            @include('livewire.partials.linear-badge', ['task' => $task])
            <span class="size-1.5 rounded-full bg-{{ $task->project->color }}-500"></span>
            {{ $task->project->name }}
        </span>
    </button>

    {{-- Stale --}}
    @if ($stale)
        <flux:tooltip :content="__('Niet bijgewerkt sinds').' '.$touched?->translatedFormat('j M Y').' ('.$touched?->diffForHumans(short: true).')'">
            <flux:badge color="amber" size="sm" icon="exclamation-triangle">{{ __('Verouderd') }}</flux:badge>
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

    {{-- Labels (click to change) --}}
    <div class="hidden shrink-0 sm:block">
        @include('livewire.partials.label-picker', ['task' => $task])
    </div>

    {{-- Due (click to change) --}}
    <div class="hidden shrink-0 sm:block">
        @include('livewire.partials.due-picker', ['task' => $task])
    </div>

    {{-- Assignee (click to change) --}}
    <div class="shrink-0">
        @include('livewire.partials.assignee-picker', ['task' => $task])
    </div>

    {{-- Actions --}}
    <div wire:sort:ignore class="hidden shrink-0 items-center gap-0.5 opacity-0 transition group-hover:opacity-100 sm:flex">
        @if ($stale)
            <flux:tooltip :content="__('Markeer als bijgewerkt')">
                <flux:button wire:click="markReviewed({{ $task->id }})" variant="subtle" size="xs" icon="check" inset="top bottom" />
            </flux:tooltip>
        @endif
        @if (auth()->user()?->canCopyPrompt())
            <flux:tooltip :content="__('Kopieer als AI-prompt')">
                <flux:button wire:click="copyPrompt({{ $task->id }})" variant="subtle" size="xs" icon="clipboard-document" inset="top bottom" />
            </flux:tooltip>
        @endif
    </div>
</div>
