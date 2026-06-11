<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="font-display text-4xl leading-none text-zinc-900 dark:text-zinc-50">{{ __('Projecten') }}</h1>
            <flux:subheading class="mt-1.5">{{ __('Kies een project of maak een nieuwe aan.') }}</flux:subheading>
        </div>

        @if ($this->canManage())
            <flux:modal.trigger name="create-project">
                <flux:button variant="primary" icon="plus">{{ __('Nieuw project') }}</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    @if ($this->projects->isEmpty())
        <flux:callout icon="rectangle-stack" variant="secondary">
            <flux:callout.heading>{{ __('Nog geen projecten') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Maak je eerste project aan om een board te starten.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->projects as $project)
                <a href="{{ route('projects.board', $project) }}" wire:navigate wire:key="project-{{ $project->id }}"
                    class="group flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 transition hover:border-zinc-300 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">
                    <div class="flex items-center gap-3">
                        <span class="size-2.5 shrink-0 rounded-full bg-{{ $project->color }}-500"></span>
                        <span class="flex-1 truncate font-display text-2xl leading-none text-zinc-900 group-hover:text-brand-700 dark:text-zinc-50 dark:group-hover:text-brand-300">{{ $project->name }}</span>
                    </div>

                    @if ($project->description)
                        <flux:text class="line-clamp-2">{{ $project->description }}</flux:text>
                    @endif

                    <div class="mt-auto flex items-center gap-4 pt-2">
                        <flux:text size="sm" class="flex items-center gap-1.5">
                            <flux:icon name="check-circle" variant="micro" class="text-zinc-400" />
                            {{ $project->open_tasks_count }} {{ __('open') }}
                        </flux:text>
                        <flux:text size="sm" class="text-zinc-400">
                            {{ $project->total_tasks_count }} {{ __('totaal') }}
                        </flux:text>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    <flux:modal name="create-project" class="md:w-96">
        <form wire:submit="createProject" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Nieuw project') }}</flux:heading>
                <flux:subheading>{{ __('Geef je project een naam en kleur.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Naam')" placeholder="{{ __('Mijn project') }}" autofocus />

            <flux:select wire:model="color" :label="__('Kleur')">
                @foreach (['blue' => 'Blauw', 'indigo' => 'Indigo', 'purple' => 'Paars', 'pink' => 'Roze', 'red' => 'Rood', 'orange' => 'Oranje', 'amber' => 'Amber', 'green' => 'Groen', 'zinc' => 'Grijs'] as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="clientId" :label="__('Klant')" placeholder="{{ __('Geen klant') }}" clearable>
                @foreach ($this->clients as $client)
                    <flux:select.option :value="$client->id">{{ $client->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="description" :label="__('Omschrijving')" :placeholder="__('Optioneel')" rows="2" />

            <flux:separator text="{{ __('AI-context (optioneel)') }}" />

            <flux:input wire:model="repoPath" :label="__('Repository-pad')" placeholder="~/Herd/mijn-project" />
            <flux:input wire:model="stack" :label="__('Stack')" placeholder="Laravel 13, Livewire 4, Tailwind v4" />
            <flux:textarea wire:model="context" :label="__('Conventies / context')" :placeholder="__('Bv. SSR Blade, Pest-tests, Nederlandse UI-teksten')" rows="2" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Annuleren') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Aanmaken') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
