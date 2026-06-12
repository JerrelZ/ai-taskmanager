<div class="-m-6 flex h-[calc(100%+3rem)] w-[calc(100%+3rem)] flex-1 flex-col overflow-hidden lg:-m-8 lg:h-[calc(100%+4rem)] lg:w-[calc(100%+4rem)]">
    {{-- Header --}}
    <div class="flex items-center justify-between gap-4 border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
        <div class="flex items-center gap-3">
            <flux:button :href="route('projects.board', $project)" wire:navigate variant="ghost" size="sm" icon="arrow-left" inset="left" />
            <span class="size-3 rounded-full bg-{{ $project->color }}-500"></span>
            <h1 class="font-display text-2xl leading-none text-zinc-900 dark:text-zinc-50">{{ $project->name }}</h1>
            <flux:badge size="sm" color="zinc">{{ __('Inbox') }}</flux:badge>
        </div>

        <div class="flex items-center gap-2">
            @if ($this->account())
                <flux:select wire:model.live="categoryFilter" size="sm" placeholder="{{ __('Alle categorieën') }}" class="max-w-[200px]">
                    <flux:select.option value="">{{ __('Alle categorieën') }}</flux:select.option>
                    @foreach (\App\Enums\EmailCategory::cases() as $category)
                        <flux:select.option :value="$category->value">{{ $category->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @if (auth()->user()->isTeam())
                <flux:button wire:click="openSettings" variant="subtle" size="sm" icon="cog-6-tooth" />
            @endif
        </div>
    </div>

    @if (! $this->account())
        <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
            <flux:icon name="inbox" class="size-10 text-zinc-400" />
            <flux:heading size="lg">{{ __('Nog geen inbox gekoppeld') }}</flux:heading>
            <flux:text class="max-w-sm text-zinc-500">
                {{ __('Koppel een IMAP-postvak aan dit project om e-mails hier binnen te halen, te groeperen en te beantwoorden.') }}
            </flux:text>
            @if (auth()->user()->isTeam())
                <flux:button wire:click="openSettings" variant="primary" size="sm" icon="link">{{ __('Inbox koppelen') }}</flux:button>
            @endif
        </div>
    @else
        <div class="flex min-h-0 flex-1">
            {{-- Left: thread list grouped by category --}}
            <div class="w-80 shrink-0 overflow-y-auto border-r border-zinc-200 dark:border-zinc-700">
                @forelse ($this->groupedThreads as $category => $threads)
                    <div class="px-3 pt-4">
                        <flux:badge size="sm" :color="$this->categoryColor($category)">
                            {{ $this->categoryLabel($category) }}
                        </flux:badge>
                    </div>

                    @foreach ($threads as $thread)
                        <button type="button" wire:key="thread-{{ $thread->id }}" wire:click="selectThread({{ $thread->id }})"
                            @class([
                                'flex w-full flex-col gap-1 border-b border-zinc-100 px-4 py-3 text-left transition hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800',
                                'bg-zinc-100 dark:bg-zinc-800' => $selectedThreadId === $thread->id,
                            ])>
                            <div class="flex items-center justify-between gap-2">
                                <span @class(['truncate text-sm text-zinc-900 dark:text-zinc-100', 'font-semibold' => ! $thread->is_read])>
                                    {{ $thread->subject ?: __('(geen onderwerp)') }}
                                </span>
                                @unless ($thread->is_read)
                                    <span class="size-2 shrink-0 rounded-full bg-blue-500"></span>
                                @endunless
                            </div>
                            @if ($thread->ai_summary)
                                <span class="line-clamp-2 text-xs text-zinc-500">{{ $thread->ai_summary }}</span>
                            @endif
                            <div class="flex items-center justify-between text-[11px] text-zinc-400">
                                <span>{{ $thread->messages_count }} {{ __('berichten') }}</span>
                                <span>{{ $thread->last_message_at?->diffForHumans() }}</span>
                            </div>
                        </button>
                    @endforeach
                @empty
                    <div class="p-6 text-center text-sm text-zinc-500">{{ __('Nog geen e-mails.') }}</div>
                @endforelse
            </div>

            {{-- Center: messages of the selected thread --}}
            <div class="flex min-h-0 min-w-0 flex-1 flex-col">
                @if ($this->selectedThread)
                    <div class="shrink-0 border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                        <flux:heading size="lg">{{ $this->selectedThread->subject ?: __('(geen onderwerp)') }}</flux:heading>
                    </div>

                    <div class="flex flex-1 flex-col gap-4 overflow-y-auto p-6">
                        @foreach ($this->selectedThread->messages as $message)
                            <flux:card wire:key="msg-{{ $message->id }}" @class(['ml-8' => $message->direction === 'outbound'])>
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <div class="text-sm">
                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $message->from_name ?: $message->from_email ?: __('Onbekend') }}</span>
                                        @if ($message->from_email)
                                            <span class="text-zinc-400">&lt;{{ $message->from_email }}&gt;</span>
                                        @endif
                                    </div>
                                    <span class="text-xs text-zinc-400">{{ $message->sent_at?->format('d-m-Y H:i') }}</span>
                                </div>

                                @if ($message->status === \App\Models\EmailMessage::STATUS_PARSE_FAILED)
                                    <flux:callout variant="warning" icon="exclamation-triangle">
                                        {{ __('Dit bericht kon niet verwerkt worden. De originele e-mail is bewaard.') }}
                                    </flux:callout>
                                @else
                                    @php($body = \App\Support\EmailBody::split($message->text_body, $message->html_body))
                                    <div class="whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-300">{{ $body['visible'] }}</div>

                                    @if ($body['quoted'])
                                        <div x-data="{ show: false }" class="mt-2">
                                            <button type="button" x-on:click="show = !show"
                                                class="inline-flex items-center gap-1 rounded bg-zinc-100 px-2 py-0.5 text-xs text-zinc-500 transition hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700">
                                                <flux:icon name="ellipsis-horizontal" class="size-4" />
                                                <span x-show="!show">{{ __('Geciteerde tekst tonen') }}</span>
                                                <span x-show="show" x-cloak>{{ __('Geciteerde tekst verbergen') }}</span>
                                            </button>
                                            <div x-show="show" x-cloak
                                                class="mt-2 whitespace-pre-wrap border-l-2 border-zinc-200 pl-3 text-xs text-zinc-400 dark:border-zinc-700">{{ $body['quoted'] }}</div>
                                        </div>
                                    @endif
                                @endif
                            </flux:card>
                        @endforeach
                    </div>

                    @if (auth()->user()->isTeam())
                        <div class="shrink-0 border-t border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                            <form wire:submit="sendReply" class="flex flex-col gap-2">
                                <flux:textarea wire:model="replyBody" rows="2"
                                    placeholder="{{ __('Typ je antwoord...') }}" />
                                <div class="flex items-center justify-between">
                                    <flux:text class="text-xs text-zinc-400">
                                        {{ __('Antwoord aan :naam', ['naam' => $this->selectedThread->messages->where('direction', 'inbound')->last()?->from_email ?? __('afzender')]) }}
                                    </flux:text>
                                    <flux:button type="submit" variant="primary" size="sm" icon="paper-airplane"
                                        wire:loading.attr="disabled" wire:target="sendReply">
                                        {{ __('Versturen') }}
                                    </flux:button>
                                </div>
                            </form>
                        </div>
                    @endif
                @else
                    <div class="flex flex-1 items-center justify-center text-sm text-zinc-500">
                        {{ __('Selecteer een gesprek om te lezen.') }}
                    </div>
                @endif
            </div>

            {{-- Right: AI/project context panel --}}
            @if ($this->selectedThread)
                <div class="w-80 shrink-0 overflow-y-auto border-l border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/30"
                    wire:key="context-{{ $this->selectedThread->id }}" wire:init="loadContext">
                    <flux:heading size="sm" class="mb-3 flex items-center gap-2">
                        <flux:icon name="sparkles" class="size-4" /> {{ __('Context') }}
                    </flux:heading>

                    @if ($context === null)
                        <div class="flex items-center gap-2 text-sm text-zinc-500">
                            <flux:icon.loading class="size-4" /> {{ __('Context laden...') }}
                        </div>
                    @else
                        <pre class="whitespace-pre-wrap font-sans text-xs leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $context }}</pre>
                    @endif
                </div>
            @endif
        </div>
    @endif

    {{-- Account settings --}}
    @if (auth()->user()->isTeam())
        <flux:modal name="inbox-settings" class="md:w-[32rem]">
            <form wire:submit="saveAccount" class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Inbox-instellingen') }}</flux:heading>

                <flux:input wire:model="emailAddress" type="email" :label="__('E-mailadres')" placeholder="support@bedrijf.nl" />

                <div class="grid grid-cols-3 gap-3">
                    <flux:input wire:model="imapHost" :label="__('IMAP-host')" class="col-span-2" placeholder="imap.bedrijf.nl" />
                    <flux:input wire:model="imapPort" type="number" :label="__('Poort')" />
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <flux:input wire:model="smtpHost" :label="__('SMTP-host')" class="col-span-2" placeholder="smtp.bedrijf.nl" />
                    <flux:input wire:model="smtpPort" type="number" :label="__('Poort')" />
                </div>

                <flux:input wire:model="username" :label="__('Gebruikersnaam')" placeholder="support@bedrijf.nl" />
                <flux:input wire:model="accountPassword" type="password" :label="__('App-wachtwoord')"
                    :description="$this->account() ? __('Laat leeg om het huidige wachtwoord te behouden.') : null" />

                <flux:input wire:model="syncDays" type="number" min="1" max="3650" :label="__('E-mails ophalen tot (dagen terug)')"
                    placeholder="30" :description="__('Bij het eerste ophalen worden alleen e-mails vanaf dit aantal dagen terug binnengehaald. Laat leeg voor de volledige geschiedenis.')" />

                <div class="flex items-center justify-between">
                    <flux:button wire:click="testConnection" type="button" variant="ghost" size="sm" icon="signal">
                        {{ __('Verbinding testen') }}
                    </flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Opslaan') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
