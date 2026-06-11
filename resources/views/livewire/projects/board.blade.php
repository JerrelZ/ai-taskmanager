<div class="flex h-full w-full flex-1 flex-col overflow-hidden">
    {{-- Header --}}
    <div class="flex flex-col gap-3 border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <flux:button href="{{ route('projects.index') }}" wire:navigate variant="ghost" size="sm" icon="arrow-left" inset="left" />
                <span class="size-3 rounded-full bg-{{ $project->color }}-500"></span>
                <h1 class="font-display text-2xl leading-none text-zinc-900 dark:text-zinc-50">{{ $project->name }}</h1>
                <flux:button wire:click="openProjectSettings" variant="subtle" size="sm" icon="cog-6-tooth" inset="top bottom" />
            </div>

            <flux:radio.group wire:model.live="boardView" variant="segmented" size="sm">
                <flux:radio value="kanban" icon="view-columns">{{ __('Board') }}</flux:radio>
                <flux:radio value="list" icon="list-bullet">{{ __('Lijst') }}</flux:radio>
                <flux:radio value="chat" icon="chat-bubble-left-right">{{ __('Chat') }}</flux:radio>
            </flux:radio.group>
        </div>

        {{-- Filters --}}
        <div @class(['flex flex-wrap items-center gap-2', 'hidden' => $boardView === 'chat'])>
            <flux:input wire:model.live.debounce.300ms="search" size="sm" icon="magnifying-glass"
                placeholder="{{ __('Zoeken...') }}" class="max-w-xs" clearable />

            <flux:select wire:model.live="assigneeFilter" size="sm" placeholder="{{ __('Toegewezen aan') }}" class="max-w-[180px]">
                <flux:select.option value="">{{ __('Iedereen') }}</flux:select.option>
                @foreach ($this->users as $user)
                    <flux:select.option :value="$user->id">{{ $user->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="labelFilter" size="sm" placeholder="{{ __('Label') }}" class="max-w-[160px]">
                <flux:select.option value="">{{ __('Alle labels') }}</flux:select.option>
                @foreach ($this->labels as $label)
                    <flux:select.option :value="$label->id">{{ $label->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="priorityFilter" size="sm" placeholder="{{ __('Prioriteit') }}" class="max-w-[160px]">
                <flux:select.option value="">{{ __('Alle prioriteiten') }}</flux:select.option>
                @foreach ($this->priorities() as $priority)
                    <flux:select.option :value="$priority->value">{{ $priority->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->hasActiveFilters())
                <flux:button wire:click="clearFilters" size="sm" variant="subtle" icon="x-mark">{{ __('Wissen') }}</flux:button>
            @endif
        </div>
    </div>

    {{-- Board body --}}
    @if ($boardView === 'kanban')
        <div class="flex-1 overflow-x-auto overflow-y-hidden">
            <div class="flex h-full gap-4 p-4">
                @foreach ($this->statuses() as $status)
                    @php $tasks = $this->columns[$status->value]; @endphp
                    <div wire:key="col-{{ $status->value }}" class="flex h-full w-80 shrink-0 flex-col rounded-xl bg-zinc-50 dark:bg-zinc-900/50">
                        <div class="flex items-center gap-2 px-3 py-3">
                            <span class="size-2.5 rounded-full bg-{{ $status->color() }}-500"></span>
                            <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                            <flux:badge size="sm" variant="pill">{{ $tasks->count() }}</flux:badge>
                        </div>

                        <div
                            wire:sort="moveTask"
                            wire:sort:group="tasks"
                            wire:sort:group-id="{{ $status->value }}"
                            class="flex-1 space-y-2 overflow-y-auto px-2 pb-2"
                        >
                            @foreach ($tasks as $task)
                                @include('livewire.projects.partials.task-card', ['task' => $task])
                            @endforeach
                        </div>

                        <div class="p-2">
                            <form wire:submit="createTask('{{ $status->value }}')">
                                <flux:input wire:model="newTaskTitle.{{ $status->value }}" size="sm"
                                    placeholder="{{ __('+ Nieuwe task') }}" kbd="↵" />
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif ($boardView === 'chat')
        <div class="flex-1 overflow-hidden">
            <livewire:projects.chat :project="$project" :key="'chat-'.$project->id" />
        </div>
    @else
        <div class="flex-1 overflow-y-auto">
            <div class="mx-auto max-w-4xl divide-y divide-zinc-100 p-4 dark:divide-zinc-800">
                @foreach ($this->statuses() as $status)
                    @php $tasks = $this->columns[$status->value]; @endphp
                    <div wire:key="list-col-{{ $status->value }}" class="py-2">
                        <div class="flex items-center gap-2 px-3 py-2">
                            <span class="size-2.5 rounded-full bg-{{ $status->color() }}-500"></span>
                            <flux:heading size="sm">{{ $status->label() }}</flux:heading>
                            <flux:badge size="sm" variant="pill">{{ $tasks->count() }}</flux:badge>
                        </div>

                        <div
                            wire:sort="moveTask"
                            wire:sort:group="tasks"
                            wire:sort:group-id="{{ $status->value }}"
                            class="min-h-[8px] space-y-0.5"
                        >
                            @foreach ($tasks as $task)
                                @include('livewire.projects.partials.task-row', ['task' => $task])
                            @endforeach
                        </div>

                        <form wire:submit="createTask('{{ $status->value }}')" class="px-3 pt-1">
                            <flux:input wire:model="newTaskTitle.{{ $status->value }}" size="sm" variant="filled"
                                placeholder="{{ __('+ Nieuwe task') }}" kbd="↵" />
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <livewire:tasks.task-detail />

    <flux:modal name="project-settings" class="md:w-[28rem]">
        <form wire:submit="saveProject" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Projectinstellingen') }}</flux:heading>
                <flux:subheading>{{ __('Context wordt meegestuurd in de AI-prompt van elk ticket.') }}</flux:subheading>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="editName" :label="__('Naam')" />
                <flux:input wire:model="editKey" :label="__('Ticket-prefix')" placeholder="WEB" />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <flux:select wire:model="editColor" :label="__('Kleur')">
                    @foreach (['blue' => 'Blauw', 'indigo' => 'Indigo', 'purple' => 'Paars', 'pink' => 'Roze', 'red' => 'Rood', 'orange' => 'Oranje', 'amber' => 'Amber', 'green' => 'Groen', 'zinc' => 'Grijs'] as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="editStatus" :label="__('Status')">
                    @foreach ($this->projectStatuses() as $status)
                        <flux:select.option :value="$status->value">{{ $status->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:select wire:model="editClientId" :label="__('Klant')" placeholder="{{ __('Geen klant') }}" clearable>
                @foreach ($this->clients as $client)
                    <flux:select.option :value="$client->id">{{ $client->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="editDescription" :label="__('Omschrijving')" rows="2" />

            <flux:separator text="{{ __('AI-context') }}" />

            <flux:input wire:model="editRepoPath" :label="__('Repository-pad')" placeholder="~/Herd/mijn-project" />
            <flux:input wire:model="editStack" :label="__('Stack')" placeholder="Laravel 13, Livewire 4, Tailwind v4" />
            <flux:textarea wire:model="editContext" :label="__('Conventies / context')" rows="3" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Annuleren') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Opslaan') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
