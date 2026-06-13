<div class="flex h-full w-full flex-1 flex-col overflow-hidden">
    {{-- Header --}}
    <div class="flex flex-col gap-3 border-b border-zinc-200 px-4 py-4 lg:px-6 dark:border-zinc-700">
        <div class="flex items-end justify-between gap-4">
            <div>
                <h1 class="font-display text-3xl leading-none text-zinc-900 lg:text-4xl dark:text-zinc-50">{{ __('Alle tickets') }}</h1>
                <flux:subheading class="mt-1.5">{{ __('Sleep om prioriteit te bepalen over alle projecten heen.') }}</flux:subheading>
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

    <div class="flex-1 overflow-y-auto">
        {{-- "Nu" block --}}
        @if ($this->nowTask)
            @php $now = $this->nowTask; @endphp
            <div class="border-b border-zinc-200 px-4 py-6 lg:px-6 dark:border-zinc-800">
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
                        <button type="button" wire:click="openTask({{ $now->id }})" class="block max-w-full truncate text-start font-display text-3xl leading-tight text-zinc-900 hover:text-brand-700 dark:text-zinc-50 dark:hover:text-brand-300">
                            {{ $now->title }}
                        </button>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <flux:button wire:click="copyPrompt({{ $now->id }})" size="sm" variant="subtle" icon="clipboard-document">{{ __('Prompt') }}</flux:button>
                        <flux:button wire:click="openTask({{ $now->id }})" size="sm" variant="primary">{{ __('Openen') }}</flux:button>
                    </div>
                </div>
            </div>
        @endif

        {{-- Ranked list --}}
        @if ($this->tickets->isEmpty())
            <div class="flex flex-col items-center justify-center gap-2 py-20 text-center">
                <flux:icon name="inbox" class="size-10 text-zinc-300 dark:text-zinc-600" />
                <flux:heading>{{ __('Geen tickets') }}</flux:heading>
                <flux:subheading>{{ __('Pas je filters aan of maak een ticket aan in een project.') }}</flux:subheading>
            </div>
        @else
            <div wire:sort="reorder" class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($this->tickets as $task)
                    @include('livewire.partials.ticket-row', ['task' => $task])
                @endforeach
            </div>
        @endif
    </div>

    <livewire:tasks.task-detail />
</div>
