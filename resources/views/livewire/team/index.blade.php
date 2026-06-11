<div class="flex h-full w-full flex-1 flex-col gap-6 p-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-4xl leading-none text-zinc-900 dark:text-zinc-50">{{ __('Team & klanten') }}</h1>
            <flux:subheading class="mt-1.5">{{ __('Beheer gebruikers en hun toegang.') }}</flux:subheading>
        </div>
        <flux:modal.trigger name="create-user">
            <flux:button variant="primary" icon="plus">{{ __('Gebruiker toevoegen') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Naam') }}</flux:table.column>
                <flux:table.column>{{ __('E-mail') }}</flux:table.column>
                <flux:table.column>{{ __('Rol') }}</flux:table.column>
                <flux:table.column>{{ __('Klant') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->users as $user)
                    <flux:table.row wire:key="user-{{ $user->id }}">
                        <flux:table.cell>
                            <span class="flex items-center gap-2">
                                <flux:avatar size="xs" circle :name="$user->name" :initials="$user->initials()" />
                                {{ $user->name }}
                            </span>
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$user->isClient() ? 'amber' : ($user->isAdmin() ? 'brand' : 'zinc')">{{ $user->role->label() }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ $user->client?->name ?? '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="create-user" class="md:w-[28rem]">
        <form wire:submit="createUser" class="space-y-6">
            <flux:heading size="lg">{{ __('Gebruiker toevoegen') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Naam')" autofocus />
            <flux:input wire:model="email" type="email" :label="__('E-mail')" />

            <flux:select wire:model.live="role" :label="__('Rol')">
                @foreach ($this->roles() as $role)
                    <flux:select.option :value="$role->value">{{ $role->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($role === 'client')
                <flux:select wire:model="clientId" :label="__('Klant')" placeholder="{{ __('Kies klant') }}">
                    @foreach ($this->clients as $client)
                        <flux:select.option :value="$client->id">{{ $client->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input wire:model="password" type="password" :label="__('Tijdelijk wachtwoord')" :description="__('De gebruiker kan dit later wijzigen.')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Annuleren') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Toevoegen') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
