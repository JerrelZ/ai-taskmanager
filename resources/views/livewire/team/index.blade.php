<div class="flex h-full w-full flex-1 flex-col gap-6 p-4 lg:p-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl leading-none text-zinc-900 lg:text-4xl dark:text-zinc-50">{{ __('Team & klanten') }}</h1>
            <flux:subheading class="mt-1.5">{{ __('Beheer gebruikers en hun toegang.') }}</flux:subheading>
        </div>
        <flux:modal.trigger name="invite-user">
            <flux:button variant="primary" icon="paper-airplane" class="shrink-0">
                <span class="max-sm:hidden">{{ __('Gebruiker uitnodigen') }}</span>
                <span class="sm:hidden">{{ __('Uitnodigen') }}</span>
            </flux:button>
        </flux:modal.trigger>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 px-4 dark:border-zinc-700">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Naam') }}</flux:table.column>
                <flux:table.column>{{ __('E-mail') }}</flux:table.column>
                <flux:table.column>{{ __('Rol') }}</flux:table.column>
                <flux:table.column>{{ __('Klant') }}</flux:table.column>
                <flux:table.column />
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
                        <flux:table.cell>
                            <div class="flex items-center justify-end">
                                <flux:button wire:click="editPassword({{ $user->id }})" size="xs" variant="subtle" icon="key" :tooltip="__('Wachtwoord wijzigen')" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- Outstanding invitations --}}
    @if ($this->invitations->isNotEmpty())
        <div class="space-y-2">
            <flux:subheading>{{ __('Openstaande uitnodigingen') }}</flux:subheading>
            <div class="overflow-x-auto rounded-xl border border-zinc-200 px-4 dark:border-zinc-700">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('E-mail') }}</flux:table.column>
                        <flux:table.column>{{ __('Rol') }}</flux:table.column>
                        <flux:table.column>{{ __('Verloopt') }}</flux:table.column>
                        <flux:table.column />
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->invitations as $invitation)
                            <flux:table.row wire:key="invite-{{ $invitation->id }}">
                                <flux:table.cell class="text-zinc-700 dark:text-zinc-200">{{ $invitation->email }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" color="zinc">{{ $invitation->role->label() }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="text-zinc-500">{{ $invitation->expires_at?->diffForHumans() }}</flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button wire:click="resendInvitation({{ $invitation->id }})" size="xs" variant="subtle" icon="paper-airplane" :tooltip="__('Opnieuw versturen')" />
                                        <flux:button wire:click="revokeInvitation({{ $invitation->id }})" wire:confirm="{{ __('Uitnodiging intrekken?') }}" size="xs" variant="subtle" icon="trash" :tooltip="__('Intrekken')" />
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endif

    <flux:modal name="invite-user" class="md:w-[28rem]">
        <form wire:submit="sendInvite" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Gebruiker uitnodigen') }}</flux:heading>
                <flux:subheading>{{ __('De uitgenodigde stelt zelf een naam en wachtwoord in.') }}</flux:subheading>
            </div>

            <flux:input wire:model="email" type="email" :label="__('E-mail')" autofocus />

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

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Annuleren') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Uitnodiging versturen') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="change-password" class="md:w-[28rem]">
        <form wire:submit="updateUserPassword" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Wachtwoord wijzigen') }}</flux:heading>
                <flux:subheading>
                    {{ __('Stel een nieuw wachtwoord in voor :name.', ['name' => $this->passwordUser?->name ?? '']) }}
                </flux:subheading>
            </div>

            <flux:input wire:model="password" type="password" :label="__('Nieuw wachtwoord')" viewable autocomplete="new-password" />
            <flux:input wire:model="password_confirmation" type="password" :label="__('Bevestig wachtwoord')" viewable autocomplete="new-password" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Annuleren') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="key">{{ __('Wachtwoord opslaan') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
