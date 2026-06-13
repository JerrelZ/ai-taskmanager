<div class="-m-6 flex h-dvh w-[calc(100%+3rem)] flex-col overflow-hidden lg:-m-8 lg:w-[calc(100%+4rem)]">
    {{-- Header --}}
    <div class="flex shrink-0 items-center justify-between gap-4 border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
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
                    <div class="flex shrink-0 items-center justify-between gap-3 border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                        <flux:heading size="lg" class="min-w-0 truncate">{{ $this->selectedThread->subject ?: __('(geen onderwerp)') }}</flux:heading>

                        @if (auth()->user()->isTeam())
                            <div class="flex shrink-0 items-center gap-2">
                                {{-- Assign thread to a teammate --}}
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="subtle" size="sm" icon="user-circle" icon:trailing="chevron-down">
                                        {{ $this->selectedThread->assignee?->name ?? __('Niet toegewezen') }}
                                    </flux:button>
                                    <flux:menu>
                                        <flux:menu.item wire:click="assignThread(null)" icon="x-mark">{{ __('Niet toegewezen') }}</flux:menu.item>
                                        <flux:menu.separator />
                                        @foreach ($this->assignableUsers as $user)
                                            <flux:menu.item wire:click="assignThread({{ $user->id }})"
                                                :icon="$this->selectedThread->assignee_id === $user->id ? 'check' : null">
                                                {{ $user->name }}
                                            </flux:menu.item>
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>

                                @if ($this->threadTicket)
                                    <flux:badge as="a" :href="route('tickets.index')" wire:navigate size="sm" color="emerald" icon="ticket">
                                        {{ $this->threadTicket->identifier() }}
                                    </flux:badge>
                                @else
                                    <flux:button wire:click="openTicketModal" variant="subtle" size="sm" icon="ticket">
                                        {{ __('Maak ticket') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Newest first: only the display order is reversed; the underlying
                         chronological collection still drives reply/sender logic. --}}
                    <div class="flex flex-1 flex-col gap-4 overflow-y-auto p-6">
                        @foreach ($this->selectedThread->messages->reverse() as $message)
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
                            {{-- Quick actions: AI draft + templates --}}
                            <div class="mb-2 flex items-center gap-2">
                                <flux:button wire:click="draftReply" variant="subtle" size="xs" icon="sparkles"
                                    wire:loading.attr="disabled" wire:target="draftReply">
                                    <span wire:loading.remove wire:target="draftReply">{{ __('AI-concept') }}</span>
                                    <span wire:loading wire:target="draftReply">{{ __('Genereren...') }}</span>
                                </flux:button>

                                <flux:dropdown position="top" align="start">
                                    <flux:button variant="subtle" size="xs" icon="chat-bubble-bottom-center-text" icon:trailing="chevron-up">
                                        {{ __('Sjablonen') }}
                                    </flux:button>
                                    <flux:menu>
                                        @forelse ($this->replyTemplates as $template)
                                            <flux:menu.item wire:click="insertTemplate({{ $template->id }})">{{ $template->name }}</flux:menu.item>
                                        @empty
                                            <flux:menu.item disabled>{{ __('Nog geen sjablonen') }}</flux:menu.item>
                                        @endforelse
                                        <flux:menu.separator />
                                        <flux:menu.item x-on:click="$flux.modal('reply-templates').show()" icon="cog-6-tooth">
                                            {{ __('Sjablonen beheren') }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>

                            <form wire:submit="sendReply" class="flex flex-col gap-2">
                                <flux:textarea wire:model="replyBody" rows="3"
                                    placeholder="{{ __('Typ je antwoord...') }}"
                                    wire:loading.attr="disabled" wire:target="draftReply" />
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

                    {{-- Sender ↔ external database link --}}
                    @if (auth()->user()->isTeam() && $this->account()?->external_db_dsn && $this->senderForLink())
                        <div class="mb-4 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                            <flux:heading size="sm" class="mb-2 flex items-center gap-2">
                                <flux:icon name="identification" class="size-4" /> {{ __('Gekoppeld contact') }}
                            </flux:heading>

                            @if ($this->linkedContact)
                                @php($row = $this->linkedContactRow)
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $this->linkedContact->label ?: $this->linkedContact->external_id }}
                                        </div>
                                        <div class="text-[11px] text-zinc-400">
                                            {{ $this->linkedContact->external_table }} · {{ $this->linkedContact->external_id_column }}={{ $this->linkedContact->external_id }}
                                        </div>
                                    </div>
                                    <flux:button wire:click="unlinkContact" variant="subtle" size="xs" icon="x-mark"
                                        :tooltip="__('Ontkoppelen')" />
                                </div>

                                @if ($row)
                                    <dl class="mt-2 space-y-0.5 border-t border-zinc-100 pt-2 text-xs dark:border-zinc-800">
                                        @foreach (array_slice($row['fields'], 0, 8, true) as $key => $value)
                                            <div class="flex justify-between gap-2">
                                                <dt class="shrink-0 text-zinc-400">{{ $key }}</dt>
                                                <dd class="truncate text-zinc-700 dark:text-zinc-300">{{ \Illuminate\Support\Str::limit((string) $value, 40) }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @else
                                    <flux:text class="mt-2 text-xs text-zinc-400">{{ __('Rij niet gevonden in de database.') }}</flux:text>
                                @endif
                            @elseif ($showLinkPanel)
                                @php($suggestions = $this->contactSuggestions)
                                @if (count($suggestions) > 0)
                                    <flux:text class="mb-2 text-xs text-zinc-500">{{ __('Suggesties voor :email', ['email' => $this->senderForLink()]) }}</flux:text>
                                    <div class="space-y-1">
                                        @foreach ($suggestions as $s)
                                            <button type="button" wire:key="sugg-{{ $s['table'] }}-{{ $s['id'] }}"
                                                wire:click="linkContact(@js($s['table']), @js($s['id_column']), @js($s['id']), @js($s['label']))"
                                                class="block w-full rounded border border-zinc-200 px-2 py-1.5 text-left text-xs transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $s['label'] }}</span>
                                                <span class="text-zinc-400"> · {{ $s['table'] }}</span>
                                                <div class="truncate text-[11px] text-zinc-400">{{ $s['preview'] }}</div>
                                            </button>
                                        @endforeach
                                    </div>
                                @else
                                    <flux:text class="mb-2 text-xs text-zinc-500">{{ __('Geen automatische match gevonden. Koppel handmatig:') }}</flux:text>
                                @endif

                                {{-- Manual fallback --}}
                                <form wire:submit="linkManual" class="mt-3 space-y-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                                    <flux:input wire:model="manualTable" size="sm" :placeholder="__('tabel (bv. customers)')" />
                                    <div class="grid grid-cols-2 gap-2">
                                        <flux:input wire:model="manualIdColumn" size="sm" :placeholder="__('id-kolom')" />
                                        <flux:input wire:model="manualId" size="sm" :placeholder="__('waarde')" />
                                    </div>
                                    <flux:button type="submit" variant="primary" size="xs" class="w-full">{{ __('Handmatig koppelen') }}</flux:button>
                                </form>
                            @else
                                <flux:button wire:click="$set('showLinkPanel', true)" variant="subtle" size="xs" icon="link" class="w-full">
                                    {{ __('Koppel afzender') }}
                                </flux:button>
                            @endif
                        </div>
                    @endif

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

    {{-- Manage reply templates --}}
    @if (auth()->user()->isTeam())
        <flux:modal name="reply-templates" class="md:w-[36rem]">
            <div class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Antwoordsjablonen') }}</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-500">
                        {{ __('Variabelen:') }}
                        <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">@{{sender}}</code>,
                        <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">@{{contact}}</code>,
                        <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">@{{agent}}</code>.
                    </flux:text>
                </div>

                @if ($this->replyTemplates->isNotEmpty())
                    <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                        @foreach ($this->replyTemplates as $template)
                            <div class="flex items-start justify-between gap-3 p-3">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $template->name }}
                                        @unless ($template->project_id)
                                            <flux:badge size="sm" color="zinc">{{ __('Globaal') }}</flux:badge>
                                        @endunless
                                    </div>
                                    <div class="line-clamp-2 text-xs text-zinc-500">{{ $template->body }}</div>
                                </div>
                                @if ($template->project_id)
                                    <flux:button wire:click="deleteTemplate({{ $template->id }})" variant="subtle" size="xs" icon="trash"
                                        :tooltip="__('Verwijderen')" />
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <form wire:submit="saveTemplate" class="flex flex-col gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:input wire:model="templateName" :label="__('Naam')" placeholder="{{ __('Bijv. Ontvangstbevestiging') }}" />
                    <flux:textarea wire:model="templateBody" rows="4" :label="__('Inhoud')" :placeholder="__('Beste klant, bedankt voor je bericht...')" />
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" size="sm" icon="plus">{{ __('Sjabloon toevoegen') }}</flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endif

    {{-- Create ticket from the selected thread --}}
    @if (auth()->user()->isTeam())
        <flux:modal name="create-ticket" class="md:w-[34rem]">
            <form wire:submit="createTicket" class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Ticket aanmaken') }}</flux:heading>

                <flux:input wire:model="ticketTitle" :label="__('Titel')" />

                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <flux:label>{{ __('Omschrijving') }}</flux:label>
                        @if ($this->account()?->external_db_dsn)
                            <flux:button wire:click="enrichTicketContext" type="button" variant="subtle" size="xs" icon="sparkles"
                                wire:loading.attr="disabled" wire:target="enrichTicketContext">
                                <span wire:loading.remove wire:target="enrichTicketContext">{{ __('AI-context uit database') }}</span>
                                <span wire:loading wire:target="enrichTicketContext">{{ __('Database onderzoeken...') }}</span>
                            </flux:button>
                        @endif
                    </div>
                    <flux:textarea wire:model="ticketDescription" rows="8"
                        wire:loading.attr="disabled" wire:target="enrichTicketContext" />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <flux:select wire:model="ticketPriority" :label="__('Prioriteit')">
                        @foreach (\App\Enums\TaskPriority::cases() as $priority)
                            <flux:select.option :value="$priority->value">{{ $priority->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="ticketAssigneeId" :label="__('Toegewezen aan')" :placeholder="__('Niemand')">
                        <flux:select.option :value="null">{{ __('Niemand') }}</flux:select.option>
                        @foreach ($this->assignableUsers as $user)
                            <flux:select.option :value="$user->id">{{ $user->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Annuleren') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="ticket">{{ __('Ticket aanmaken') }}</flux:button>
                </div>
            </form>
        </flux:modal>
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
