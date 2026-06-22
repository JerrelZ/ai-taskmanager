{{--
    Shared board card. Used by both the project board and the cross-project
    tickets board, so both stay visually identical. Toggle the context-specific
    bits with the options below:

    - $showProject (bool)      Show the project dot + name and a coloured left
                               border. On for the cross-project tickets board.
    - $canSelect (bool)        Render the bulk-select checkbox (team only). It
                               only shows on hover / when selected to keep the
                               resting card clean.
    - $showStale (bool)        Render the "needs review" warning button. Requires
                               a `markReviewed(int $id)` action on the component.
    - $selectedTickets (array) Currently selected task ids (drives the checkbox
                               + selected ring).

    The host component must expose: openTask, setStatus, setPriority, setDue,
    toggleLabel, setAssignee and copyPrompt.
--}}
@php
    $showProject ??= false;
    $canSelect ??= false;
    $showStale ??= false;
    $selectedTickets ??= [];

    $progress = $task->subtaskProgress();
    $isSelected = in_array($task->id, $selectedTickets);
    $stale = $showStale && $task->isStale();
    $touched = $stale ? $task->lastTouchedAt() : null;
@endphp
<div wire:key="task-card-{{ $task->id }}" wire:sort:item="{{ $task->id }}"
    wire:click="openTask({{ $task->id }})" @class([
        'group cursor-pointer rounded-lg border border-zinc-200 bg-white p-3 shadow-sm transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600',
        'border-s-4 dark:border-s-' . $task->project->color . '-500' => $showProject,
        'ring-1 ring-brand-400' => $isSelected,
        'opacity-60' => $task->isComplete(),
    ])>
    {{-- Identity + status / drag --}}
    <div class="mb-1.5 flex items-center justify-between gap-2">
        <span class="flex min-w-0 items-center gap-1.5 leading-none">
            @if ($canSelect)
                <span wire:sort:ignore @class([
                    'shrink-0',
                    'hidden group-hover:block focus-within:block' => !$isSelected,
                ])>
                    <flux:checkbox wire:model.live="selectedTickets" value="{{ $task->id }}" x-on:click.stop />
                </span>
            @endif
            @if ($showProject)
                <span class="size-2 shrink-0 rounded-full bg-{{ $task->project->color }}-500"></span>
                <span class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $task->project->name }}</span>
            @endif
            <span class="font-mono text-[0.7rem] tracking-tight text-zinc-400">{{ $task->identifier() }}</span>
            <div class="-mt-1"> @include('livewire.partials.linear-badge', ['task' => $task])
            </div>
        </span>

        <div class="flex shrink-0 items-center gap-1">
            {{-- Mobile: tap the status pill to move the card to another column. --}}
            <flux:dropdown wire:sort:ignore position="bottom" align="end" class="lg:hidden">
                <flux:badge as="button" type="button" x-on:click.stop :color="$task->status->color()" size="sm"
                    rounded class="cursor-pointer">
                    {{ $task->status->label() }}
                </flux:badge>
                <flux:menu>
                    @foreach (\App\Enums\TaskStatus::cases() as $columnStatus)
                        <flux:menu.item x-on:click.stop
                            wire:click="setStatus({{ $task->id }}, '{{ $columnStatus->value }}')"
                            :icon="$task->status === $columnStatus ? 'check' : null">
                            {{ $columnStatus->label() }}
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            {{-- Desktop: drag handle. --}}
            <button type="button" wire:sort:handle x-on:click.stop
                class="-me-1 hidden size-7 shrink-0 cursor-grab touch-none items-center justify-center rounded text-zinc-300 transition hover:text-zinc-500 active:cursor-grabbing lg:flex lg:opacity-0 lg:group-hover:opacity-100 dark:text-zinc-600 dark:hover:text-zinc-300"
                aria-label="{{ __('Versleep kaart') }}">
                <flux:icon name="bars-3" variant="micro" />
            </button>
        </div>
    </div>

    {{-- Title --}}
    <div class="flex items-start justify-between gap-2">
        <p @class([
            'min-w-0 break-words text-sm font-medium text-zinc-800 dark:text-zinc-100',
            'line-through' => $task->status === \App\Enums\TaskStatus::Canceled,
        ])>{{ $task->title }}</p>
        <div class="flex shrink-0 items-center gap-0.5">
            @if ($stale)
                <flux:tooltip :content="__('Niet bijgewerkt sinds').' '.$touched?->translatedFormat('j M Y')">
                    <flux:button wire:click.stop="markReviewed({{ $task->id }})" variant="subtle" size="xs"
                        icon="exclamation-triangle" inset="top bottom" class="text-amber-500!" />
                </flux:tooltip>
            @endif
            @if (auth()->user()?->canCopyPrompt())
                <div wire:sort:ignore class="opacity-0 transition group-hover:opacity-100">
                    <flux:tooltip :content="__('Kopieer als AI-prompt')">
                        <flux:button wire:click.stop="copyPrompt({{ $task->id }})" variant="subtle" size="xs"
                            icon="clipboard-document" inset="top bottom" />
                    </flux:tooltip>
                </div>
            @endif
        </div>
    </div>

    {{-- Meta row --}}
    <div class="mt-3 flex items-center gap-3 text-zinc-400">
        @include('livewire.partials.priority-picker', ['task' => $task])

        @include('livewire.partials.due-picker', ['task' => $task])

        @include('livewire.partials.label-picker', ['task' => $task])

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

        @include('livewire.partials.assignee-picker', ['task' => $task])
    </div>
</div>
