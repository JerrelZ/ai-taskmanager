<div
    x-data
    x-effect="$store.mobileNav.hiddenForChat = $wire.conversationId !== null"
    x-on:livewire:navigating.window="$store.mobileNav.hiddenForChat = false"
    @class([
        '-m-6 flex w-[calc(100%+3rem)] overflow-hidden lg:-m-8 lg:h-dvh lg:w-[calc(100%+4rem)]',
        'h-[calc(100dvh-3.5rem)]' => $this->activeConversation !== null,
        'h-[calc(100dvh-3.5rem-4rem)]' => $this->activeConversation === null,
    ])
    wire:poll.3s="pollMessages"
>
    @php $me = auth()->user(); @endphp

    {{-- Conversation list --}}
    <div @class([
        'w-full shrink-0 flex-col border-e border-zinc-200 lg:w-80 dark:border-zinc-700',
        'hidden lg:flex' => $this->activeConversation !== null,
        'flex' => $this->activeConversation === null,
    ])>
        <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-4 py-4 dark:border-zinc-700">
            <h1 class="font-display text-2xl leading-none text-zinc-900 dark:text-zinc-50">{{ __('Berichten') }}</h1>
            @if ($me->isTeam())
                <flux:dropdown>
                    <flux:button size="sm" variant="primary" icon="plus" />
                    <flux:menu>
                        <flux:modal.trigger name="new-dm">
                            <flux:menu.item icon="user">{{ __('Nieuw gesprek') }}</flux:menu.item>
                        </flux:modal.trigger>
                        <flux:modal.trigger name="new-group">
                            <flux:menu.item icon="user-group">{{ __('Nieuwe groep') }}</flux:menu.item>
                        </flux:modal.trigger>
                    </flux:menu>
                </flux:dropdown>
            @endif
        </div>

        <div class="flex-1 overflow-y-auto">
            @forelse ($this->conversations as $conversation)
                @php
                    $unread = $conversation->unreadCountFor($me);
                    $active = $conversation->id === $this->conversationId;
                @endphp
                <button type="button" wire:key="conv-{{ $conversation->id }}" wire:click="openConversation({{ $conversation->id }})"
                    @class([
                        'flex w-full items-center gap-3 border-b border-zinc-100 px-4 py-3 text-start transition dark:border-zinc-800',
                        'bg-brand-50 dark:bg-brand-950/30' => $active,
                        'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' => ! $active,
                    ])>
                    <div @class([
                        'flex size-9 shrink-0 items-center justify-center rounded-full',
                        'bg-'.($conversation->project?->color ?? 'zinc').'-500/15 text-'.($conversation->project?->color ?? 'zinc').'-600' => $conversation->type === \App\Enums\ConversationType::Project,
                        'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' => $conversation->type !== \App\Enums\ConversationType::Project,
                    ])>
                        <flux:icon :name="$conversation->type === \App\Enums\ConversationType::Project ? 'rectangle-stack' : ($conversation->type === \App\Enums\ConversationType::Group ? 'user-group' : 'user')" variant="micro" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $conversation->titleFor($me) }}</span>
                            @if ($conversation->latestMessage)
                                <span class="shrink-0 text-xs text-zinc-400">{{ $conversation->latestMessage->created_at->diffForHumans(short: true) }}</span>
                            @endif
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-xs text-zinc-400">{{ \Illuminate\Support\Str::limit($conversation->latestMessage?->body, 32) ?: __('Nog geen berichten') }}</span>
                            @if ($unread > 0)
                                <flux:badge size="sm" color="brand" variant="pill">{{ $unread }}</flux:badge>
                            @endif
                        </div>
                    </div>
                </button>
            @empty
                <div class="p-6 text-center text-sm text-zinc-400">{{ __('Nog geen gesprekken.') }}</div>
            @endforelse
        </div>
    </div>

    {{-- Active thread --}}
    <div @class([
        'min-w-0 flex-1 flex-col',
        'hidden lg:flex' => $this->activeConversation === null,
        'flex' => $this->activeConversation !== null,
    ])>
        @if ($this->activeConversation)
            @php $conversation = $this->activeConversation; @endphp
            <div class="flex items-center gap-3 border-b border-zinc-200 px-4 py-4 lg:px-6 dark:border-zinc-700">
                <flux:button wire:click="$set('conversationId', null)" variant="subtle" size="sm" icon="arrow-left" class="lg:hidden" />
                <flux:heading size="lg" class="min-w-0 flex-1 truncate">{{ $conversation->titleFor($me) }}</flux:heading>
                @if ($conversation->type === \App\Enums\ConversationType::Project && $conversation->project)
                    <flux:button :href="route('projects.board', $conversation->project)" wire:navigate size="sm" variant="ghost" icon="arrow-up-right">{{ __('Project') }}</flux:button>
                @endif
                <flux:button
                    wire:click="toggleMute"
                    size="sm"
                    variant="ghost"
                    :icon="$this->activeMuted ? 'bell-slash' : 'bell'"
                    :tooltip="$this->activeMuted ? __('Meldingen aanzetten') : __('Dempen')"
                />
            </div>

            <x-chat.thread
                :messages="$this->thread"
                :me="$me"
                :show-sender-names="$conversation->type !== \App\Enums\ConversationType::Dm"
                :can-draft-ticket="true"
            />

            <x-chat.composer :mentions="$this->people->pluck('name')" :pending="$newChatAttachments" />
        @else
            <div class="flex h-full flex-col items-center justify-center gap-2 text-center text-zinc-400">
                <flux:icon name="chat-bubble-left-right" class="size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:heading>{{ __('Kies een gesprek') }}</flux:heading>
                <flux:subheading>{{ __('Of start een nieuw gesprek.') }}</flux:subheading>
            </div>
        @endif
    </div>

    {{-- New DM --}}
    <flux:modal name="new-dm" class="md:w-96">
        <form wire:submit="startDm" class="space-y-6">
            <flux:heading size="lg">{{ __('Nieuw gesprek') }}</flux:heading>
            <flux:select wire:model="newDmUserId" :label="__('Met wie?')" placeholder="{{ __('Kies persoon') }}">
                @foreach ($this->people as $person)
                    <flux:select.option :value="$person->id">{{ $person->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Annuleren') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Starten') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Message -> ticket --}}
    <flux:modal name="message-to-ticket" class="md:w-96">
        <form wire:submit="createTicketFromMessage" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Ticket van bericht') }}</flux:heading>
                <flux:subheading>{{ __('AI maakt er een nette titel + omschrijving van.') }}</flux:subheading>
            </div>
            <flux:select wire:model="ticketProjectId" :label="__('Project')" placeholder="{{ __('Kies project') }}">
                @foreach ($this->ticketProjects as $project)
                    <flux:select.option :value="$project->id">{{ $project->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Annuleren') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="sparkles">{{ __('Maak ticket') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- New group --}}
    <flux:modal name="new-group" class="md:w-96">
        <form wire:submit="createGroup" class="space-y-6">
            <flux:heading size="lg">{{ __('Nieuwe groep') }}</flux:heading>
            <flux:input wire:model="newGroupName" :label="__('Naam')" placeholder="{{ __('bijv. Design') }}" />
            <flux:select wire:model.live="newGroupProjectId" :label="__('Project')" placeholder="{{ __('Kies een project') }}">
                @foreach ($this->groupProjects as $project)
                    <flux:select.option :value="$project->id">{{ $project->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select
                wire:model="newGroupMembers"
                variant="listbox"
                multiple
                :label="__('Leden')"
                placeholder="{{ __('Kies leden') }}"
                :disabled="$newGroupProjectId === null"
            >
                @foreach ($this->groupMembers as $person)
                    <flux:select.option :value="$person->id">{{ $person->name }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($newGroupProjectId === null)
                <flux:text size="sm" class="-mt-4 text-zinc-500">{{ __('Kies eerst een project om leden te kunnen toevoegen.') }}</flux:text>
            @endif
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Annuleren') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Aanmaken') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
