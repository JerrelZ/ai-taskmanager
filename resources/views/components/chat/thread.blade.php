@props([
    'messages',
    'me',
    'showSenderNames' => true,
    'canDraftTicket' => false,
    'canReply' => false,
    'canReact' => false,
    'conversation' => null,
    'hasMore' => false,
])

@php
    // Quick-reaction palette shown on hover.
    $quickReactions = ['👍', '❤️', '😂', '🎉', '😮', '🙏'];

    // The newest message I sent — the only one that carries a read receipt.
    $lastMineId = $messages->where('user_id', $me->id)->last()?->id;
    $isDm = $conversation?->type === \App\Enums\ConversationType::Dm;
@endphp

<div class="flex min-h-0 flex-1 flex-col">
    <div
        x-data="chatThread({{ $conversation?->id ?? 'null' }})"
        x-on:message-sent.window="scrollToBottom()"
        class="flex flex-1 flex-col overflow-y-auto bg-zinc-100 px-3 py-4 lg:px-6 dark:bg-zinc-900"
    >
        @if ($messages->isEmpty())
            <div class="flex flex-1 flex-col items-center justify-center gap-2 text-center text-zinc-400">
                <flux:icon name="chat-bubble-left-right" class="size-10 text-zinc-300 dark:text-zinc-600" />
                {{ $slot->isNotEmpty() ? $slot : __('Start het gesprek.') }}
            </div>
        @else
        {{-- mt-auto keeps the conversation pinned to the bottom (WhatsApp-style) while staying scrollable. --}}
        <div class="mt-auto flex flex-col gap-0.5">
        @if ($hasMore)
            <div class="flex justify-center pb-2">
                <button
                    type="button"
                    x-on:click="loadOlder()"
                    wire:loading.attr="disabled"
                    wire:target="loadOlder"
                    class="rounded-full bg-white px-3 py-1 text-xs font-medium text-zinc-500 shadow-sm ring-1 ring-zinc-200 transition hover:bg-zinc-50 disabled:opacity-50 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-700"
                >
                    {{ __('Toon oudere berichten') }}
                </button>
            </div>
        @endif
        @php $prev = null; @endphp
        @foreach ($messages as $message)
            @php
                $mine = $message->user_id === $me->id;
                $newDay = $prev === null || ! $message->created_at->isSameDay($prev->created_at);
                $startsGroup = $newDay
                    || $prev->user_id !== $message->user_id
                    || $message->created_at->diffInMinutes($prev->created_at) >= 5;
                $showName = ! $mine && $showSenderNames && $startsGroup;
                $media = $message->attachments->filter(fn ($attachment) => $attachment->isPreviewable());
                $files = $message->attachments->reject(fn ($attachment) => $attachment->isPreviewable());
                $prev = $message;

                $dayLabel = match (true) {
                    $message->created_at->isToday() => __('Vandaag'),
                    $message->created_at->isYesterday() => __('Gisteren'),
                    $message->created_at->isCurrentYear() => $message->created_at->locale('nl')->isoFormat('D MMMM'),
                    default => $message->created_at->locale('nl')->isoFormat('D MMMM YYYY'),
                };
            @endphp

            @if ($newDay)
                <div class="my-3 flex items-center justify-center">
                    <span class="rounded-full bg-zinc-200/80 px-3 py-1 text-xs font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $dayLabel }}</span>
                </div>
            @endif
            <div wire:key="msg-{{ $message->id }}" @class([
                'group flex items-end gap-2',
                'flex-row-reverse' => $mine,
                'mt-3' => $startsGroup && ! $loop->first,
            ])>
                @unless ($mine)
                    @if ($startsGroup)
                        <flux:avatar size="xs" circle :name="$message->user?->name ?? '?'" :initials="$message->user?->initials() ?? '?'" class="shrink-0" />
                    @else
                        <div class="size-6 shrink-0"></div>
                    @endif
                @endunless

                <div @class(['flex min-w-0 max-w-[78%] flex-col sm:max-w-[70%]', 'items-end' => $mine])>
                <div @class([
                    'relative rounded-2xl px-2.5 py-1.5 shadow-sm',
                    'bg-brand-500 text-white' => $mine,
                    'bg-white text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100' => ! $mine,
                    'rounded-tr-md' => $mine && $startsGroup,
                    'rounded-tl-md' => ! $mine && $startsGroup,
                ])>
                    @if ($showName)
                        <div class="mb-0.5 text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $message->user?->name ?? __('Onbekend') }}</div>
                    @endif

                    @if ($message->replyTo)
                        <div @class([
                            'mb-1 border-s-2 ps-2 text-xs',
                            'border-white/40 text-white/80' => $mine,
                            'border-zinc-300 text-zinc-500 dark:border-zinc-600 dark:text-zinc-400' => ! $mine,
                        ])>
                            <div class="font-medium">{{ $message->replyTo->user?->name ?? __('Onbekend') }}</div>
                            <div class="truncate">{{ \Illuminate\Support\Str::limit($message->replyTo->body, 60) ?: __('Bijlage') }}</div>
                        </div>
                    @endif

                    @if ($media->isNotEmpty())
                        <div @class([
                            'mb-1 grid gap-1',
                            'grid-cols-2' => $media->count() > 1,
                            'grid-cols-1' => $media->count() === 1,
                        ])>
                            @foreach ($media as $item)
                                <button
                                    type="button"
                                    x-on:click="$dispatch('attachment-open', {
                                        url: '{{ route('attachments.show', $item) }}',
                                        download: '{{ route('attachments.download', $item) }}',
                                        type: '{{ $item->isVideo() ? 'video' : 'image' }}',
                                    })"
                                    @class([
                                        'relative block w-full cursor-zoom-in overflow-hidden rounded-lg',
                                        'max-h-72' => $media->count() === 1,
                                        'aspect-square' => $media->count() > 1,
                                    ])
                                >
                                    @if ($item->isVideo())
                                        <video src="{{ route('attachments.show', $item) }}#t=0.1" preload="metadata" muted playsinline class="size-full {{ $media->count() === 1 ? 'max-h-72' : '' }} object-cover"></video>
                                        <span class="absolute inset-0 flex items-center justify-center bg-black/20">
                                            <span class="flex size-9 items-center justify-center rounded-full bg-black/50 text-white">
                                                <flux:icon name="play" variant="solid" class="size-5" />
                                            </span>
                                        </span>
                                    @else
                                        <img src="{{ route('attachments.show', $item) }}" alt="{{ $item->filename }}" loading="lazy" class="w-full object-cover {{ $media->count() === 1 ? 'max-h-72' : 'aspect-square size-full' }}" />
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @if ($files->isNotEmpty())
                        <div class="mb-1 flex flex-col gap-1">
                            @foreach ($files as $file)
                                <a href="{{ route('attachments.download', $file) }}" @class([
                                    'flex items-center gap-2 rounded-lg px-2 py-1.5',
                                    'bg-white/15 hover:bg-white/25' => $mine,
                                    'bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-700/60 dark:hover:bg-zinc-700' => ! $mine,
                                ])>
                                    <div @class([
                                        'flex size-8 shrink-0 items-center justify-center rounded-md',
                                        'bg-white/20' => $mine,
                                        'bg-white text-brand-500 dark:bg-zinc-800' => ! $mine,
                                    ])>
                                        <flux:icon name="document" class="size-4" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="truncate text-xs font-medium">{{ $file->filename }}</div>
                                        <div @class(['text-[10px]', 'text-white/60' => $mine, 'text-zinc-400' => ! $mine])>{{ $file->humanSize() }}</div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif

                    @if (filled($message->body))
                        <div @class([
                            'text-sm break-words',
                            'prose-mentions-light' => $mine,
                        ])>{!! \App\Support\Mentions::render($message->body, null, $conversation?->project) !!}</div>
                    @endif

                    <div @class([
                        'mt-0.5 text-right text-[10px] leading-none',
                        'text-white/70' => $mine,
                        'text-zinc-400' => ! $mine,
                    ])>{{ $message->created_at->format('H:i') }}</div>
                </div>

                @php $reactions = $message->reactionSummary($me); @endphp
                @if ($reactions->isNotEmpty())
                    <div @class(['mt-1 flex flex-wrap gap-1', 'justify-end' => $mine])>
                        @foreach ($reactions as $reaction)
                            <button
                                type="button"
                                wire:click="toggleReaction({{ $message->id }}, '{{ $reaction['emoji'] }}')"
                                @class([
                                    'flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-xs leading-none transition',
                                    'border-brand-300 bg-brand-50 text-brand-700 dark:border-brand-700 dark:bg-brand-950/50 dark:text-brand-300' => $reaction['reacted'],
                                    'border-zinc-200 bg-white text-zinc-500 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400' => ! $reaction['reacted'],
                                ])
                            >
                                <span>{{ $reaction['emoji'] }}</span>
                                <span class="tabular-nums">{{ $reaction['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                @if ($isDm && $message->id === $lastMineId)
                    @php
                        $other = $conversation->users->firstWhere('id', '!=', $me->id);
                        $seenAt = $other?->pivot?->last_read_at;
                        $seen = $seenAt !== null && \Illuminate\Support\Carbon::parse($seenAt)->gte($message->created_at);
                    @endphp
                    <div class="mt-0.5 flex items-center gap-0.5 text-[10px] text-zinc-400">
                        <flux:icon :name="$seen ? 'check-circle' : 'check'" variant="micro" class="size-3" />
                        {{ $seen ? __('Gelezen') : __('Verzonden') }}
                    </div>
                @endif
                </div>

                @if ($canReply || $canReact || $canDraftTicket)
                    <div class="flex items-center gap-0.5 opacity-0 transition group-hover:opacity-100" x-data="{ react: false }">
                        @if ($canReact)
                            <div class="relative" x-on:click.outside="react = false">
                                <flux:tooltip :content="__('Reageer')">
                                    <flux:button x-on:click="react = !react" size="xs" variant="subtle" icon="face-smile" inset="top bottom" />
                                </flux:tooltip>
                                <div
                                    x-show="react"
                                    x-cloak
                                    x-transition.origin.bottom
                                    @class(['absolute bottom-full z-20 mb-1 flex gap-0.5 rounded-full border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800', 'end-0' => $mine, 'start-0' => ! $mine])
                                >
                                    @foreach ($quickReactions as $emoji)
                                        <button type="button" wire:click="toggleReaction({{ $message->id }}, '{{ $emoji }}')" x-on:click="react = false" class="flex size-7 items-center justify-center rounded-full text-base transition hover:bg-zinc-100 dark:hover:bg-zinc-700">{{ $emoji }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if ($canReply)
                            <flux:tooltip :content="__('Antwoord')">
                                <flux:button wire:click="startReply({{ $message->id }})" size="xs" variant="subtle" icon="arrow-uturn-left" inset="top bottom" />
                            </flux:tooltip>
                        @endif

                        @if ($canDraftTicket)
                            <flux:tooltip :content="__('Maak ticket van dit bericht')">
                                <flux:button wire:click="openTicketDraft({{ $message->id }})" size="xs" variant="subtle" icon="sparkles" inset="top bottom" />
                            </flux:tooltip>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
        </div>

        {{-- Typing indicator (realtime whisper); hidden when nobody is typing. --}}
        <div x-show="typingName" x-cloak class="px-1 pt-1 text-xs italic text-zinc-400">
            <span x-text="typingName"></span> {{ __('is aan het typen…') }}
        </div>

        {{-- Jump back to the newest message; surfaces while reading older history. --}}
        <div x-show="!pinned" x-cloak x-transition class="pointer-events-none sticky bottom-2 z-10 flex justify-center">
            <button
                type="button"
                x-on:click="pinned = true; hasNew = false; scrollToBottom()"
                class="pointer-events-auto flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs font-medium text-zinc-600 shadow-md ring-1 ring-zinc-200 transition hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-700 dark:hover:bg-zinc-700"
            >
                <span x-show="hasNew" x-cloak class="flex size-2 rounded-full bg-brand-500"></span>
                <span x-text="hasNew ? '{{ __('Nieuwe berichten') }}' : '{{ __('Naar beneden') }}'"></span>
                <flux:icon name="arrow-down" class="size-3.5" />
            </button>
        </div>
        @endif
    </div>

    <x-attachment-viewer />
</div>
