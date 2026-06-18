<div class="flex h-full w-full flex-1 flex-col overflow-hidden">
    {{-- Header --}}
    <div class="flex flex-col gap-3 border-b border-zinc-200 px-4 py-4 lg:px-6 dark:border-zinc-700">
        <div class="flex items-end justify-between gap-4">
            <div>
                <h1 class="font-display text-3xl leading-none text-zinc-900 lg:text-4xl dark:text-zinc-50">{{ __('Klaar voor Claude Code') }}</h1>
                <flux:subheading class="mt-1.5">{{ __('Tickets met genoeg context om als prompt te plakken. Kopieer en plak direct in Claude Code.') }}</flux:subheading>
            </div>
            <span class="font-display text-3xl leading-none text-zinc-400">{{ $this->readyTickets->count() }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:select wire:model.live="projectFilter" size="sm" placeholder="{{ __('Project') }}" class="max-w-[200px]">
                <flux:select.option value="">{{ __('Alle projecten') }}</flux:select.option>
                @foreach ($this->projects as $project)
                    <flux:select.option :value="$project->id">{{ $project->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div class="flex-1 space-y-8 overflow-y-auto px-4 py-6 lg:px-6">
        {{-- Ready --}}
        <section>
            <div class="mb-3 flex items-center gap-2">
                <flux:icon name="check-circle" variant="micro" class="text-green-500" />
                <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Klaar om te plakken') }}</h2>
            </div>

            @forelse ($this->readyTickets as $task)
                <div wire:key="ready-{{ $task->id }}" class="mb-2 flex items-center gap-3 rounded-lg border border-zinc-200 bg-white px-3 py-2.5 dark:border-zinc-700 dark:bg-zinc-900">
                    @if ($task->priority !== \App\Enums\TaskPriority::None)
                        <flux:tooltip :content="$task->priority->label()">
                            <flux:icon :name="$task->priority->icon()" variant="micro" class="shrink-0 text-{{ $task->priority->color() }}-500" />
                        </flux:tooltip>
                    @endif

                    <button type="button" wire:click="openTask({{ $task->id }})" class="flex min-w-0 flex-1 flex-col items-start text-start">
                        <span class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $task->title }}</span>
                        <span class="flex items-center gap-1.5 text-xs text-zinc-400">
                            <span class="font-mono text-[0.7rem] tracking-tight">{{ $task->identifier() }}</span>
                            <span class="size-1.5 rounded-full bg-{{ $task->project->color }}-500"></span>
                            {{ $task->project->name }}
                        </span>
                    </button>

                    @if (auth()->user()?->canCopyPrompt())
                        <flux:button wire:click="copyPrompt({{ $task->id }})" size="sm" variant="primary" icon="clipboard-document">{{ __('Prompt kopiëren') }}</flux:button>
                    @endif
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-zinc-200 px-4 py-10 text-center dark:border-zinc-700">
                    <flux:subheading>{{ __('Nog geen tickets die volledig klaar zijn.') }}</flux:subheading>
                </div>
            @endforelse
        </section>

        {{-- Almost --}}
        @if ($this->almostTickets->isNotEmpty())
            <section>
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon name="exclamation-circle" variant="micro" class="text-amber-500" />
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Bijna klaar — mist nog context') }}</h2>
                </div>

                @foreach ($this->almostTickets as $task)
                    <div wire:key="almost-{{ $task->id }}" class="mb-2 rounded-lg border border-amber-200 bg-amber-50/40 px-3 py-2.5 dark:border-amber-900/50 dark:bg-amber-950/20">
                        <div class="flex items-center gap-3">
                            @if ($task->priority !== \App\Enums\TaskPriority::None)
                                <flux:icon :name="$task->priority->icon()" variant="micro" class="shrink-0 text-{{ $task->priority->color() }}-500" />
                            @endif

                            <button type="button" wire:click="openTask({{ $task->id }})" class="flex min-w-0 flex-1 flex-col items-start text-start">
                                <span class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $task->title }}</span>
                                <span class="flex items-center gap-1.5 text-xs text-zinc-400">
                                    <span class="font-mono text-[0.7rem] tracking-tight">{{ $task->identifier() }}</span>
                                    <span class="size-1.5 rounded-full bg-{{ $task->project->color }}-500"></span>
                                    {{ $task->project->name }}
                                </span>
                            </button>

                            <flux:tooltip :content="__('Opnieuw beoordelen')">
                                <flux:button wire:click="reassess({{ $task->id }})" size="xs" variant="subtle" icon="arrow-path" />
                            </flux:tooltip>
                            @if (auth()->user()?->canCopyPrompt())
                                <flux:button wire:click="copyPrompt({{ $task->id }})" size="sm" variant="subtle" icon="clipboard-document">{{ __('Prompt') }}</flux:button>
                            @endif
                        </div>

                        @if (! empty($task->ai_missing))
                            <ul class="mt-2 space-y-0.5 ps-7 text-xs text-amber-700 dark:text-amber-300">
                                @foreach ($task->ai_missing as $missing)
                                    <li class="flex items-start gap-1.5">
                                        <flux:icon name="minus" variant="micro" class="mt-0.5 shrink-0" />
                                        <span>{{ $missing }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif
    </div>

    <livewire:tasks.task-detail />
</div>
