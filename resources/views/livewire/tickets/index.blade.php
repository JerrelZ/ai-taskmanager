<div class="flex h-full w-full flex-1 flex-col overflow-hidden" wire:poll.10s="pollBoard">
    {{-- Header --}}
    <div class="flex flex-col gap-3 border-b border-zinc-200 px-4 py-4 lg:px-6 dark:border-zinc-700">
        <div class="flex items-end justify-between gap-4">
            <div>
                <h1 class="font-display text-3xl leading-none text-zinc-900 lg:text-4xl dark:text-zinc-50">{{ __('Alle tickets') }}</h1>
                <flux:subheading class="mt-1.5">{{ __('Sleep kaarten tussen kolommen en projecten om prioriteit te bepalen — die volgorde geldt ook op het projectbord.') }}</flux:subheading>
            </div>
            <span class="font-display text-3xl leading-none text-zinc-400">{{ $this->tickets->count() }}</span>
        </div>

        {{-- Filters --}}
        <div class="flex flex-wrap items-center gap-2">
            <flux:input wire:model.live.debounce.300ms="search" size="sm" icon="magnifying-glass"
                placeholder="{{ __('Zoeken...') }}" class="max-w-xs" clearable />

            <flux:select wire:model.live="projectFilter" size="sm" placeholder="{{ __('Project') }}" class="max-w-[170px]">
                <flux:select.option value="">{{ __('Alle projecten') }}</flux:select.option>
                @foreach ($this->projects as $project)
                    <flux:select.option :value="$project->id">{{ $project->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="assigneeFilter" size="sm" placeholder="{{ __('Persoon') }}" class="max-w-[160px]">
                <flux:select.option value="">{{ __('Iedereen') }}</flux:select.option>
                @foreach ($this->users as $user)
                    <flux:select.option :value="$user->id">{{ $user->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="priorityFilter" size="sm" placeholder="{{ __('Prioriteit') }}" class="max-w-[150px]">
                <flux:select.option value="">{{ __('Alle prio') }}</flux:select.option>
                @foreach ($this->priorities() as $priority)
                    <flux:select.option :value="$priority->value">{{ $priority->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button wire:click="$toggle('onlyStale')" size="sm" :variant="$onlyStale ? 'primary' : 'subtle'" icon="clock">
                {{ __('Verouderd') }}
            </flux:button>

            <flux:button wire:click="$toggle('showCompleted')" size="sm" :variant="$showCompleted ? 'primary' : 'subtle'" icon="check-circle">
                {{ __('Afgerond') }}
            </flux:button>

            @if ($this->hasActiveFilters())
                <flux:button wire:click="clearFilters" size="sm" variant="ghost" icon="x-mark">{{ __('Wissen') }}</flux:button>
            @endif
        </div>
    </div>

    {{-- Bulk action bar: appears when one or more tickets are selected. --}}
    @if (auth()->user()->isTeam() && count($selectedTickets) > 0)
        <div class="flex flex-wrap items-center gap-2 border-b border-zinc-200 bg-brand-50/60 px-4 py-2.5 lg:px-6 dark:border-zinc-700 dark:bg-brand-950/30">
            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">
                {{ trans_choice('{1} :count ticket geselecteerd|[2,*] :count tickets geselecteerd', count($selectedTickets), ['count' => count($selectedTickets)]) }}
            </span>

            <flux:button wire:click="toggleSelectAll" size="sm" variant="ghost">
                {{ count($selectedTickets) === $this->tickets->count() ? __('Deselecteer alles') : __('Selecteer alles') }}
            </flux:button>

            <span class="flex-1"></span>

            {{-- Status --}}
            <flux:dropdown position="bottom" align="end">
                <flux:button size="sm" variant="subtle" icon:trailing="chevron-down">{{ __('Status') }}</flux:button>
                <flux:menu>
                    @foreach (\App\Enums\TaskStatus::cases() as $statusOption)
                        <flux:menu.item wire:click="bulkSetStatus('{{ $statusOption->value }}')">{{ $statusOption->label() }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            {{-- Priority --}}
            <flux:dropdown position="bottom" align="end">
                <flux:button size="sm" variant="subtle" icon:trailing="chevron-down">{{ __('Prioriteit') }}</flux:button>
                <flux:menu>
                    @foreach ($this->priorities() as $priorityOption)
                        <flux:menu.item wire:click="bulkSetPriority('{{ $priorityOption->value }}')">
                            <span class="flex items-center gap-2">
                                <flux:icon :name="$priorityOption->icon()" variant="micro" class="text-{{ $priorityOption->color() }}-500" />
                                {{ $priorityOption->label() }}
                            </span>
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            {{-- Assignee --}}
            <flux:dropdown position="bottom" align="end">
                <flux:button size="sm" variant="subtle" icon:trailing="chevron-down">{{ __('Persoon') }}</flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="bulkSetAssignee(null)">{{ __('Niemand') }}</flux:menu.item>
                    @foreach ($this->users as $user)
                        <flux:menu.item wire:click="bulkSetAssignee({{ $user->id }})">{{ $user->name }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            {{-- Label --}}
            @if ($this->labels->isNotEmpty())
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" variant="subtle" icon:trailing="chevron-down">{{ __('Label') }}</flux:button>
                    <flux:menu class="max-h-72 overflow-y-auto">
                        @foreach ($this->labels as $label)
                            <flux:menu.item wire:click="bulkAddLabel({{ $label->id }})">
                                <span class="flex items-center gap-2">
                                    <span class="size-2 rounded-full bg-{{ $label->color }}-500"></span>
                                    {{ $label->name }}
                                </span>
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            @endif

            {{-- Project --}}
            <flux:dropdown position="bottom" align="end">
                <flux:button size="sm" variant="subtle" icon:trailing="chevron-down">{{ __('Project') }}</flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    @foreach ($this->projects() as $project)
                        <flux:menu.item wire:click="bulkSetProject({{ $project->id }})">
                            <span class="flex items-center gap-2">
                                <span class="size-2 rounded-full bg-{{ $project->color }}-500"></span>
                                {{ $project->name }}
                            </span>
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <flux:button wire:click="bulkMarkReviewed" size="sm" variant="subtle" icon="check">{{ __('Bijgewerkt') }}</flux:button>

            <flux:button wire:click="bulkDelete" wire:confirm="{{ __('Geselecteerde tickets verwijderen?') }}" size="sm" variant="danger" icon="trash">{{ __('Verwijderen') }}</flux:button>

            <flux:button wire:click="clearSelection" size="sm" variant="ghost" icon="x-mark">{{ __('Deselecteer') }}</flux:button>
        </div>
    @endif

    {{-- "Nu" block --}}
    @if ($this->nowTask)
        @php $now = $this->nowTask; @endphp
        <div class="border-b border-zinc-200 px-4 py-5 lg:px-6 dark:border-zinc-800">
            <div class="flex items-end gap-4">
                <div class="min-w-0 flex-1">
                    <div class="mb-1 flex items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 text-[0.7rem] font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-400">
                            <span class="size-1.5 rounded-full bg-brand-500"></span>
                            {{ __('Nu aan werken') }}
                        </span>
                        <span class="flex items-center gap-1 text-xs text-zinc-400">
                            <span class="size-1.5 rounded-full bg-{{ $now->project->color }}-500"></span>
                            {{ $now->project->name }}
                        </span>
                    </div>
                    <button type="button" wire:click="openTask({{ $now->id }})" class="block max-w-full truncate text-start font-display text-2xl leading-tight text-zinc-900 hover:text-brand-700 lg:text-3xl dark:text-zinc-50 dark:hover:text-brand-300">
                        {{ $now->title }}
                    </button>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if (auth()->user()?->canCopyPrompt())
                        <flux:button wire:click="copyPrompt({{ $now->id }})" size="sm" variant="subtle" icon="clipboard-document">{{ __('Prompt') }}</flux:button>
                    @endif
                    <flux:button wire:click="openTask({{ $now->id }})" size="sm" variant="primary">{{ __('Openen') }}</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Cross-project board: status columns, every project mixed. --}}
    <div class="flex-1 overflow-x-auto overflow-y-hidden">
        <div class="flex h-full gap-4 p-4">
            @foreach ($this->statuses() as $status)
                @php
                    $columnTasks = $this->columns[$status->value];
                    $limit = $this->boardColumnLimit();
                    $expanded = $this->columnIsExpanded($status->value);
                    $visibleTasks = $expanded ? $columnTasks : $columnTasks->take($limit);
                @endphp
                <div wire:key="ticket-col-{{ $status->value }}" class="group/col flex h-full w-80 shrink-0 flex-col rounded-xl bg-zinc-50 dark:bg-zinc-900/50">
                    <div class="flex items-center gap-2 px-3 py-3">
                        <span class="size-2.5 rounded-full bg-{{ $status->color() }}-500"></span>
                        <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                        <flux:badge size="sm" variant="pill">{{ $columnTasks->count() }}</flux:badge>
                        <flux:button wire:click="openNewTicket('{{ $status->value }}')" variant="subtle" size="xs"
                            icon="plus" inset="top bottom" :tooltip="__('Nieuw ticket')"
                            class="ms-auto opacity-0 transition group-hover/col:opacity-100" />
                    </div>

                    <div
                        wire:sort="moveTask"
                        wire:sort:group="tickets"
                        wire:sort:group-id="{{ $status->value }}"
                        class="flex-1 space-y-2 overflow-y-auto px-2 pb-3"
                    >
                        @foreach ($visibleTasks as $task)
                            @include('livewire.partials.task-card', [
                                'task' => $task,
                                'showProject' => true,
                                'canSelect' => auth()->user()?->isTeam(),
                                'showStale' => true,
                                'selectedTickets' => $selectedTickets,
                            ])
                        @endforeach

                        @if ($columnTasks->isEmpty())
                            <p class="px-2 py-6 text-center text-xs text-zinc-400 dark:text-zinc-600">{{ __('Geen tickets') }}</p>
                        @endif
                    </div>

                    @if (! $expanded && $columnTasks->count() > $limit)
                        <div class="px-2 pb-2">
                            <flux:button wire:click="showMoreColumn('{{ $status->value }}')" variant="subtle" size="xs" class="w-full">
                                {{ __('Toon nog :count', ['count' => $columnTasks->count() - $limit]) }}
                            </flux:button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Create a ticket from a board column (cross-project, so pick a project) --}}
    <flux:modal name="new-ticket" class="md:w-[32rem]">
        <form wire:submit="createTicket" class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Nieuw ticket') }}</flux:heading>

            <flux:select wire:model="newTicketProjectId" :label="__('Project')">
                @foreach ($this->projects as $project)
                    <flux:select.option :value="$project->id">{{ $project->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="newTicketTitle" :label="__('Titel')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Annuleren') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="plus">{{ __('Aanmaken') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <livewire:tasks.task-detail />
</div>
