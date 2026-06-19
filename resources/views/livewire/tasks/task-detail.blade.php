<flux:modal name="task-detail" class="w-[96vw] max-w-6xl">
    @if ($this->task)
        @php $task = $this->task; @endphp
        <div class="flex h-[86vh] flex-col">
            {{-- Header bar --}}
            <div class="flex shrink-0 items-center justify-between gap-2 border-b border-zinc-200 pb-3 pe-8 dark:border-zinc-700">
                <div class="flex min-w-0 items-center gap-2 text-sm text-zinc-400">
                    <a href="{{ route('projects.board', $task->project) }}" wire:navigate class="flex items-center gap-1.5 hover:text-zinc-600 dark:hover:text-zinc-300">
                        <span class="size-2.5 shrink-0 rounded-full bg-{{ $task->project->color }}-500"></span>
                        <span class="truncate">{{ $task->project->name }}</span>
                    </a>
                    <span class="shrink-0 font-mono text-xs tracking-tight text-zinc-400">{{ $task->identifier() }}</span>
                    @if ($task->isSubtask() && $task->parent)
                        <flux:icon name="chevron-right" variant="micro" class="shrink-0" />
                        <flux:link wire:click="open({{ $task->parent->id }})" class="cursor-pointer truncate">
                            {{ \Illuminate\Support\Str::limit($task->parent->title, 40) }}
                        </flux:link>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    @if (auth()->user()?->canCopyPrompt())
                        <flux:button wire:click="copyPrompt({{ $task->id }})" variant="subtle" size="sm" icon="clipboard-document"><span class="hidden sm:inline">{{ __('Kopieer prompt') }}</span></flux:button>
                    @endif
                    <flux:dropdown>
                        <flux:button variant="subtle" size="sm" icon="ellipsis-horizontal" inset="top bottom" />
                        <flux:menu>
                            <flux:menu.item wire:click="deleteTask" wire:confirm="{{ __('Deze task verwijderen?') }}" variant="danger" icon="trash">
                                {{ __('Verwijderen') }}
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>

            {{-- Mobile: compact title + property bar, kept fixed above the scrollable body. --}}
            @php
                $statusEnum = \App\Enums\TaskStatus::from($status);
                $priorityEnum = \App\Enums\TaskPriority::from($priority);
                $assigneeName = $this->users->firstWhere('id', $assigneeId)?->name;
            @endphp
            <div class="shrink-0 space-y-2 border-b border-zinc-200 py-3 lg:hidden dark:border-zinc-700">
                <flux:input wire:model.blur="title" wire:key="title-mobile-{{ $task->id }}" variant="filled" class="font-display !text-lg" placeholder="{{ __('Task titel') }}" />

                <div class="flex flex-wrap items-center gap-1.5">
                    {{-- Status --}}
                    <flux:dropdown position="bottom" align="start">
                        <flux:badge as="button" type="button" :color="$statusEnum->color()" size="sm" rounded icon:trailing="chevron-down" class="cursor-pointer">
                            {{ $statusEnum->label() }}
                        </flux:badge>
                        <flux:menu>
                            @foreach ($this->statuses() as $statusOption)
                                <flux:menu.item wire:click="$set('status', '{{ $statusOption->value }}')" :icon="$status === $statusOption->value ? 'check' : null">
                                    {{ $statusOption->label() }}
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>

                    {{-- Priority --}}
                    <flux:dropdown position="bottom" align="start">
                        <flux:badge as="button" type="button" :color="$priorityEnum->color()" size="sm" rounded :icon="$priorityEnum->icon()" icon:trailing="chevron-down" class="cursor-pointer">
                            {{ $priorityEnum->label() }}
                        </flux:badge>
                        <flux:menu>
                            @foreach ($this->priorities() as $priorityOption)
                                <flux:menu.item wire:click="$set('priority', '{{ $priorityOption->value }}')" :icon="$priority === $priorityOption->value ? 'check' : $priorityOption->icon()">
                                    {{ $priorityOption->label() }}
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>

                    {{-- Assignee --}}
                    <flux:dropdown position="bottom" align="start">
                        <flux:badge as="button" type="button" color="zinc" size="sm" rounded icon="user" icon:trailing="chevron-down" class="cursor-pointer">
                            {{ $assigneeName ?? __('Niemand') }}
                        </flux:badge>
                        <flux:menu class="max-h-72 overflow-y-auto">
                            <flux:menu.item wire:click="$set('assigneeId', null)" :icon="$assigneeId === null ? 'check' : null">{{ __('Niemand') }}</flux:menu.item>
                            @foreach ($this->users as $user)
                                <flux:menu.item wire:click="$set('assigneeId', {{ $user->id }})" :icon="$assigneeId === $user->id ? 'check' : null">{{ $user->name }}</flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>

                    {{-- Deadline --}}
                    <flux:dropdown position="bottom" align="start">
                        <flux:badge as="button" type="button" color="zinc" size="sm" rounded icon="calendar" icon:trailing="chevron-down" class="cursor-pointer">
                            {{ $dueDate ? \Illuminate\Support\Carbon::parse($dueDate)->translatedFormat('j M') : __('Deadline') }}
                        </flux:badge>
                        <flux:popover class="flex flex-col gap-2">
                            <flux:calendar wire:model.live="dueDate" />
                            @if ($dueDate)
                                <flux:button wire:click="$set('dueDate', null)" size="xs" variant="subtle" icon="x-mark">{{ __('Wissen') }}</flux:button>
                            @endif
                        </flux:popover>
                    </flux:dropdown>

                    {{-- Labels --}}
                    <flux:dropdown position="bottom" align="start">
                        <flux:badge as="button" type="button" color="zinc" size="sm" rounded icon="tag" icon:trailing="chevron-down" class="cursor-pointer">
                            {{ count($selectedLabels) > 0 ? count($selectedLabels).' '.__('labels') : __('Labels') }}
                        </flux:badge>
                        <flux:menu class="max-h-72 overflow-y-auto">
                            @foreach ($this->labels as $label)
                                <flux:menu.item wire:click="toggleLabel({{ $label->id }})" :icon="in_array($label->id, $selectedLabels) ? 'check' : null">
                                    <span class="flex items-center gap-2">
                                        <span class="size-2 rounded-full bg-{{ $label->color }}-500"></span>
                                        {{ $label->name }}
                                    </span>
                                </flux:menu.item>
                            @endforeach
                            <flux:menu.separator />
                            <div class="p-1" wire:sort:ignore>
                                <form wire:submit="createLabel">
                                    <flux:input wire:model="newLabelName" size="sm" placeholder="{{ __('Nieuw label...') }}" kbd="↵" />
                                </form>
                            </div>
                        </flux:menu>
                    </flux:dropdown>
                </div>

                {{-- Selected labels --}}
                @if (count($selectedLabels) > 0)
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($this->labels->whereIn('id', $selectedLabels) as $label)
                            <flux:badge :color="$label->color" size="sm">
                                {{ $label->name }}
                                <flux:badge.close wire:click="toggleLabel({{ $label->id }})" />
                            </flux:badge>
                        @endforeach
                    </div>
                @endif

                {{-- Freshness --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-400">
                    <span class="flex items-center gap-1.5">
                        <flux:icon name="clock" variant="micro" />
                        {{ __('Bijgewerkt') }} {{ $task->lastTouchedAt()?->diffForHumans() }}
                    </span>
                    @if ($task->isStale())
                        <span class="flex items-center gap-2">
                            <flux:badge color="amber" size="sm">{{ __('Verouderd') }}</flux:badge>
                            <flux:link wire:click="markReviewed" class="cursor-pointer">{{ __('Markeer bijgewerkt') }}</flux:link>
                        </span>
                    @endif
                </div>
            </div>

            {{-- Body: main + sidebar --}}
            <div class="flex flex-1 flex-col gap-6 overflow-hidden pt-4 lg:flex-row lg:gap-8">
                {{-- Main column --}}
                <div class="flex-1 space-y-4 overflow-y-auto pe-1 lg:space-y-6 lg:pe-3">
                    <flux:input wire:model.blur="title" wire:key="title-desktop-{{ $task->id }}" variant="filled" class="hidden font-display !text-xl lg:!block lg:!text-2xl" placeholder="{{ __('Task titel') }}" />

                    <div x-data="{ editing: false }" wire:key="desc-{{ $task->id }}">
                        <flux:subheading class="mb-1">{{ __('Omschrijving') }}</flux:subheading>

                        {{-- Read mode: click to edit --}}
                        <div x-show="! editing" x-on:click="editing = true"
                            class="prose prose-sm max-w-none cursor-text rounded-lg border border-transparent px-3 py-2 text-sm text-zinc-700 hover:border-zinc-200 dark:text-zinc-200 dark:hover:border-zinc-700">
                            @if (filled($task->description))
                                {!! $task->description !!}
                            @else
                                <span class="text-zinc-400">{{ __('Klik om een omschrijving toe te voegen…') }}</span>
                            @endif
                        </div>

                        {{-- Edit mode: rich editor --}}
                        <div x-show="editing" x-cloak>
                            <flux:editor wire:model="description" toolbar="heading | bold italic underline | bullet ordered | link" />
                            <div class="mt-2 flex justify-end gap-2">
                                <flux:button size="sm" variant="ghost" x-on:click="editing = false">{{ __('Sluiten') }}</flux:button>
                                <flux:button size="sm" variant="primary" x-on:click="$wire.saveDescription().then(() => editing = false)">{{ __('Opslaan') }}</flux:button>
                            </div>
                        </div>
                    </div>

                    {{-- Subtasks --}}
                    <div class="space-y-2">
                        @php $progress = $task->subtaskProgress(); @endphp
                        <flux:subheading>
                            {{ __('Subtasks') }}
                            @if ($progress['total'] > 0)
                                <span class="text-zinc-400">{{ $progress['done'] }}/{{ $progress['total'] }}</span>
                            @endif
                        </flux:subheading>

                        <div class="space-y-1">
                            @foreach ($task->subtasks as $subtask)
                                <div wire:key="subtask-{{ $subtask->id }}" class="group flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                    <flux:checkbox :checked="$subtask->isComplete()" wire:click="toggleSubtask({{ $subtask->id }})" />
                                    <button type="button" wire:click="open({{ $subtask->id }})" @class([
                                        'flex-1 text-start text-sm text-zinc-700 dark:text-zinc-200',
                                        'line-through text-zinc-400' => $subtask->isComplete(),
                                    ])>{{ $subtask->title }}</button>
                                    @if ($subtask->assignee)
                                        <flux:avatar size="xs" circle :name="$subtask->assignee->name" :initials="$subtask->assignee->initials()" />
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        <form wire:submit="addSubtask">
                            <flux:input wire:model="newSubtaskTitle" size="sm" variant="filled" icon="plus" placeholder="{{ __('Subtask toevoegen') }}" kbd="↵" />
                        </form>
                    </div>

                    <flux:separator />

                    {{-- Attachments --}}
                    <div class="space-y-2">
                        <flux:subheading>{{ __('Bijlagen') }}</flux:subheading>

                        <x-attachment-list :attachments="$task->attachments" delete-method="deleteAttachment" />

                        <form wire:submit="uploadAttachments" class="space-y-2"
                            x-data="{ dragging: false }"
                            x-on:dragover.prevent="dragging = true"
                            x-on:dragleave.prevent="dragging = false"
                            x-on:drop.prevent="dragging = false; $refs.fileInput.files = $event.dataTransfer.files; $refs.fileInput.dispatchEvent(new Event('change', { bubbles: true }))"
                        >
                            <label
                                x-bind:class="dragging
                                    ? 'border-blue-400 bg-blue-50 dark:border-blue-500 dark:bg-blue-500/10'
                                    : 'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600'"
                                class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-4 py-6 text-center transition"
                            >
                                <flux:icon name="arrow-up-tray" class="size-5 text-zinc-400" />
                                <span class="text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ __('Sleep bestanden hierheen of') }}
                                    <span class="font-medium text-blue-600 dark:text-blue-400">{{ __('blader') }}</span>
                                </span>
                                <span class="text-xs text-zinc-400">{{ __('Max 25 MB per bestand') }}</span>
                                <input x-ref="fileInput" type="file" wire:model="newAttachments" multiple class="hidden" />
                            </label>

                            <div wire:loading.flex wire:target="newAttachments" class="items-center gap-2 text-xs text-zinc-400">
                                <flux:icon name="loading" variant="micro" />
                                {{ __('Bezig met uploaden...') }}
                            </div>

                            @if (count($newAttachments) > 0)
                                <div class="space-y-1" wire:loading.remove wire:target="newAttachments">
                                    @foreach ($newAttachments as $index => $pending)
                                        <div wire:key="pending-{{ $index }}" class="flex items-center gap-2 rounded-md bg-zinc-50 px-2 py-1.5 dark:bg-zinc-800/50">
                                            <flux:icon name="paper-clip" class="size-4 shrink-0 text-zinc-400" />
                                            <span class="flex-1 truncate text-sm text-zinc-700 dark:text-zinc-200">{{ $pending->getClientOriginalName() }}</span>
                                            <flux:button wire:click="removeNewAttachment({{ $index }})" variant="subtle" size="xs" icon="x-mark" :tooltip="__('Verwijderen')" />
                                        </div>
                                    @endforeach
                                </div>

                                <flux:button type="submit" size="sm" variant="primary" icon="arrow-up-tray" class="w-full"
                                    wire:loading.attr="disabled" wire:target="newAttachments,uploadAttachments">
                                    {{ __('Uploaden') }}
                                </flux:button>
                            @endif
                        </form>
                        <flux:error name="newAttachments" />
                        <flux:error name="newAttachments.*" />
                    </div>

                    <flux:separator />

                    {{-- Comments --}}
                    <div class="space-y-4">
                        <flux:subheading>{{ __('Reacties') }}</flux:subheading>

                        <div class="space-y-4">
                            @forelse ($task->comments as $comment)
                                <div wire:key="comment-{{ $comment->id }}" class="flex gap-3">
                                    <flux:avatar size="sm" circle :name="$comment->user->name" :initials="$comment->user->initials()" />
                                    <div class="flex-1">
                                        <div class="flex items-baseline gap-2">
                                            <flux:heading size="sm">{{ $comment->user->name }}</flux:heading>
                                            <flux:text size="sm" class="text-zinc-400">{{ $comment->created_at->diffForHumans() }}</flux:text>
                                        </div>
                                        <div class="mt-0.5 text-sm text-zinc-700 dark:text-zinc-200">{!! \App\Support\Mentions::render($comment->body, $this->users) !!}</div>
                                    </div>
                                </div>
                            @empty
                                <flux:text size="sm" class="text-zinc-400">{{ __('Nog geen reacties.') }}</flux:text>
                            @endforelse
                        </div>

                        <form wire:submit="addComment" x-data="mentionField(@js($this->users->pluck('name')->values()))" class="flex items-end gap-2">
                            <div class="relative flex-1">
                                <textarea
                                    x-ref="input"
                                    wire:model="newComment"
                                    x-on:input="onInput()"
                                    x-on:keydown="onKeydown($event)"
                                    rows="1"
                                    placeholder="{{ __('Schrijf een reactie…') }}"
                                    class="block max-h-32 w-full resize-none rounded-lg border border-zinc-200 bg-white px-3 py-2 text-base text-zinc-800 placeholder-zinc-400 focus:border-brand-500 focus:outline-none focus:ring-0 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                                ></textarea>

                                {{-- Mention autocomplete --}}
                                <div x-show="open" x-cloak class="absolute bottom-full start-0 z-20 mb-1 max-h-48 w-64 max-w-[80vw] overflow-y-auto rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                                    <template x-for="(name, i) in matches" :key="name">
                                        <button type="button" x-on:mousedown.prevent="choose(name)" :class="i === active ? 'bg-brand-50 dark:bg-brand-950/40' : ''" class="block w-full px-3 py-2 text-start text-sm text-zinc-700 dark:text-zinc-200">@<span x-text="name"></span></button>
                                    </template>
                                </div>
                            </div>
                            <flux:button type="submit" variant="primary" icon="paper-airplane" />
                        </form>
                    </div>

                    {{-- Activity log (below comments) --}}
                    @if ($task->activities->isNotEmpty())
                        <flux:separator />
                        <div class="space-y-3">
                            <flux:subheading>{{ __('Activiteit') }}</flux:subheading>
                            <div class="space-y-2">
                                @foreach ($task->activities as $activity)
                                    <div wire:key="activity-{{ $activity->id }}" class="flex items-center gap-3 ps-1 text-xs text-zinc-400">
                                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                            <flux:icon name="arrow-path" variant="micro" />
                                        </span>
                                        <span>
                                            <span class="font-medium text-zinc-600 dark:text-zinc-300">{{ $activity->user?->name ?? __('Systeem') }}</span>
                                            {{ $activity->description() }}
                                            · {{ $activity->created_at->diffForHumans() }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Sidebar: properties (desktop only; mobile uses the compact bar up top) --}}
                <div class="hidden w-full shrink-0 space-y-5 overflow-y-auto lg:block lg:w-72 lg:border-s lg:border-zinc-200 lg:ps-6 dark:lg:border-zinc-700">
                    <flux:select wire:model.live="status" :label="__('Status')" size="sm">
                        @foreach ($this->statuses() as $status)
                            <flux:select.option :value="$status->value">{{ $status->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="priority" :label="__('Prioriteit')" size="sm">
                        @foreach ($this->priorities() as $priority)
                            <flux:select.option :value="$priority->value">{{ $priority->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model.live="assigneeId" :label="__('Toegewezen aan')" placeholder="{{ __('Niemand') }}" size="sm" clearable>
                        @foreach ($this->users as $user)
                            <flux:select.option :value="$user->id">{{ $user->name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:date-picker wire:model.live="dueDate" :label="__('Deadline')" size="sm" clearable with-today />

                    {{-- Labels --}}
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <flux:subheading>{{ __('Labels') }}</flux:subheading>
                            <flux:dropdown>
                                <flux:button variant="subtle" size="xs" icon="plus">{{ __('Label') }}</flux:button>
                                <flux:menu class="max-h-72 overflow-y-auto">
                                    @foreach ($this->labels as $label)
                                        <flux:menu.item wire:click="toggleLabel({{ $label->id }})" :icon="in_array($label->id, $selectedLabels) ? 'check' : null">
                                            <span class="flex items-center gap-2">
                                                <span class="size-2 rounded-full bg-{{ $label->color }}-500"></span>
                                                {{ $label->name }}
                                            </span>
                                        </flux:menu.item>
                                    @endforeach
                                    <flux:menu.separator />
                                    <div class="p-1" wire:sort:ignore>
                                        <form wire:submit="createLabel">
                                            <flux:input wire:model="newLabelName" size="sm" placeholder="{{ __('Nieuw label...') }}" kbd="↵" />
                                        </form>
                                    </div>
                                </flux:menu>
                            </flux:dropdown>
                        </div>

                        <div class="flex flex-wrap gap-1.5">
                            @forelse ($this->labels->whereIn('id', $selectedLabels) as $label)
                                <flux:badge :color="$label->color" size="sm">
                                    {{ $label->name }}
                                    <flux:badge.close wire:click="toggleLabel({{ $label->id }})" />
                                </flux:badge>
                            @empty
                                <flux:text size="sm" class="text-zinc-400">{{ __('Geen labels') }}</flux:text>
                            @endforelse
                        </div>
                    </div>

                    <flux:separator />

                    {{-- Freshness --}}
                    <div class="space-y-1.5 text-xs text-zinc-400">
                        <div class="flex items-center gap-1.5">
                            <flux:icon name="clock" variant="micro" />
                            {{ __('Bijgewerkt') }} {{ $task->lastTouchedAt()?->diffForHumans() }}
                        </div>
                        @if ($task->isStale())
                            <div class="flex items-center gap-2">
                                <flux:badge color="amber" size="sm">{{ __('Verouderd') }}</flux:badge>
                                <flux:link wire:click="markReviewed" class="cursor-pointer">{{ __('Markeer bijgewerkt') }}</flux:link>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <x-attachment-viewer />
    @endif
</flux:modal>
