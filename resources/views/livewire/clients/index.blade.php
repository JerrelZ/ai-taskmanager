<div class="flex h-full w-full flex-1 flex-col gap-6 p-4 lg:p-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl leading-none text-zinc-900 lg:text-4xl dark:text-zinc-50">{{ __('Klanten') }}</h1>
            <flux:subheading class="mt-1.5">{{ __('Groepeer projecten per klant.') }}</flux:subheading>
        </div>
        <flux:modal.trigger name="create-client">
            <flux:button variant="primary" icon="plus">{{ __('Nieuwe klant') }}</flux:button>
        </flux:modal.trigger>
    </div>

    @if ($this->clients->isEmpty())
        <flux:callout icon="building-office-2" variant="secondary">
            <flux:callout.heading>{{ __('Nog geen klanten') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Maak een klant aan om projecten te groeperen.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->clients as $client)
                <div wire:key="client-{{ $client->id }}" class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex items-center gap-3">
                        <span class="size-2.5 shrink-0 rounded-full bg-{{ $client->color }}-500"></span>
                        <span class="flex-1 truncate font-display text-2xl leading-none text-zinc-900 dark:text-zinc-50">{{ $client->name }}</span>
                    </div>
                    <div class="flex items-center gap-4 text-sm text-zinc-400">
                        <span>{{ $client->projects_count }} {{ __('projecten') }}</span>
                        <span>{{ $client->users_count }} {{ __('gebruikers') }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <flux:modal name="create-client" class="md:w-96">
        <form wire:submit="createClient" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Nieuwe klant') }}</flux:heading>
            </div>

            <flux:input wire:model="name" :label="__('Naam')" placeholder="{{ __('Bedrijfsnaam') }}" autofocus />

            <flux:select wire:model="color" :label="__('Kleur')">
                @foreach (['blue' => 'Blauw', 'indigo' => 'Indigo', 'purple' => 'Paars', 'pink' => 'Roze', 'red' => 'Rood', 'orange' => 'Oranje', 'amber' => 'Amber', 'green' => 'Groen', 'zinc' => 'Grijs'] as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Annuleren') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Aanmaken') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
