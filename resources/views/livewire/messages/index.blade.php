<div class="-m-6 flex h-[calc(100%+3rem)] w-[calc(100%+3rem)] flex-1 overflow-hidden lg:-m-8 lg:h-[calc(100%+4rem)] lg:w-[calc(100%+4rem)]" wire:poll.5s>
    @php $me = auth()->user(); @endphp

    {{-- Conversation list --}}
    <div class="flex w-80 shrink-0 flex-col border-e border-zinc-200 dark:border-zinc-700">
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
    <div class="flex flex-1 flex-col">
        @if ($this->activeConversation)
            @php $conversation = $this->activeConversation; @endphp
            <div class="flex items-center gap-3 border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                <flux:heading size="lg">{{ $conversation->titleFor($me) }}</flux:heading>
                @if ($conversation->type === \App\Enums\ConversationType::Project && $conversation->project)
                    <flux:button :href="route('projects.board', $conversation->project)" wire:navigate size="sm" variant="ghost" icon="arrow-up-right">{{ __('Project') }}</flux:button>
                @endif
            </div>

            <div
                x-data
                x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                class="flex-1 space-y-2 overflow-y-auto bg-zinc-50 px-6 py-6 dark:bg-zinc-900"
            >
                @forelse ($this->thread as $message)
                    @php
                        $mine = $message->user_id === $me->id;
                        $showName = ! $mine && $conversation->type !== \App\Enums\ConversationType::Dm;
                    @endphp
                    <div wire:key="msg-{{ $message->id }}" @class([
                        'group flex items-end gap-2',
                        'flex-row-reverse' => $mine,
                    ])>
                        @unless ($mine)
                            <flux:avatar size="xs" circle :name="$message->user?->name ?? '?'" :initials="$message->user?->initials() ?? '?'" class="shrink-0" />
                        @endunless

                        <div @class([
                            'relative max-w-[75%] rounded-2xl px-3 py-2 shadow-sm',
                            'rounded-br-sm bg-brand-500 text-white' => $mine,
                            'rounded-bl-sm bg-white text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100' => ! $mine,
                        ])>
                            @if ($showName)
                                <div class="mb-0.5 text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $message->user?->name ?? __('Onbekend') }}</div>
                            @endif
                            @if (filled($message->body))
                                <div @class([
                                    'text-sm break-words',
                                    'prose-mentions-light' => $mine,
                                ])>{!! \App\Support\Mentions::render($message->body) !!}</div>
                            @endif

                            @if ($message->attachments->isNotEmpty())
                                <div class="mt-1 flex flex-col gap-1">
                                    @foreach ($message->attachments as $attachment)
                                        <a href="{{ route('attachments.download', $attachment) }}" @class([
                                            'inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs',
                                            'bg-white/15 text-white hover:bg-white/25' => $mine,
                                            'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-200' => ! $mine,
                                        ])>
                                            <flux:icon :name="$attachment->isImage() ? 'photo' : 'paper-clip'" class="size-3.5" />
                                            <span class="max-w-[12rem] truncate">{{ $attachment->filename }}</span>
                                            <span @class(['text-white/60' => $mine, 'text-zinc-400' => ! $mine])>{{ $attachment->humanSize() }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                            <div @class([
                                'mt-1 text-right text-[10px] leading-none',
                                'text-white/70' => $mine,
                                'text-zinc-400' => ! $mine,
                            ])>{{ $message->created_at->format('H:i') }}</div>
                        </div>

                        <flux:tooltip :content="__('Maak ticket van dit bericht')">
                            <flux:button wire:click="openTicketDraft({{ $message->id }})" size="xs" variant="subtle" icon="sparkles" inset="top bottom" class="opacity-0 transition group-hover:opacity-100" />
                        </flux:tooltip>
                    </div>
                @empty
                    <div class="flex h-full flex-col items-center justify-center gap-2 text-center text-zinc-400">
                        <flux:icon name="chat-bubble-left-right" class="size-10 text-zinc-300 dark:text-zinc-600" />
                        {{ __('Start het gesprek.') }}
                    </div>
                @endforelse
            </div>

            <div class="border-t border-zinc-200 dark:border-zinc-700">
                <form wire:submit="send" class="flex items-end gap-2 px-6 py-4">
                    <div class="relative flex-1" x-data="mentionAutocomplete(@js($this->people->pluck('name')->values()))" x-on:input="onInput()" x-on:keydown="onKeydown($event)">
                        <flux:textarea wire:model="body" rows="1" placeholder="{{ __('Schrijf een bericht... (@naam om te taggen)') }}" />
                        <div x-show="open" x-cloak class="absolute bottom-full left-0 z-20 mb-1 max-h-48 w-64 overflow-y-auto rounded-lg border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                            <template x-for="(name, i) in matches" :key="name">
                                <button type="button" x-on:mousedown.prevent="choose(name)" :class="i === active ? 'bg-brand-50 dark:bg-brand-950/40' : ''" class="block w-full px-3 py-1.5 text-start text-sm text-zinc-700 dark:text-zinc-200">@<span x-text="name"></span></button>
                            </template>
                        </div>
                    </div>
                    <label class="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        :title="__('Bijlage toevoegen')">
                        <flux:icon name="paper-clip" class="size-5" />
                        <input type="file" wire:model="newChatAttachments" multiple class="hidden" />
                    </label>
                    <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Verstuur') }}</flux:button>
                </form>

                @if (count($newChatAttachments) > 0)
                    <div class="flex items-center gap-2 px-6 pb-3 text-xs text-zinc-500">
                        <flux:icon name="paper-clip" class="size-3.5" />
                        {{ trans_choice('{1}:count bijlage klaar om te versturen|[2,*]:count bijlagen klaar om te versturen', count($newChatAttachments), ['count' => count($newChatAttachments)]) }}
                        <span wire:loading wire:target="newChatAttachments" class="text-zinc-400">{{ __('(uploaden...)') }}</span>
                    </div>
                @endif
            </div>
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
            <flux:select wire:model="newGroupMembers" variant="listbox" multiple :label="__('Leden')" placeholder="{{ __('Kies leden') }}">
                @foreach ($this->people as $person)
                    <flux:select.option :value="$person->id">{{ $person->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Annuleren') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Aanmaken') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
