@props([
    'messages',
    'me',
    'showSenderNames' => true,
    'canDraftTicket' => false,
])

<div class="flex min-h-0 flex-1 flex-col" x-data="{ lightbox: null }" x-on:keydown.escape.window="lightbox = null">
    <div
        x-data="chatThread"
        x-on:message-sent.window="scrollToBottom()"
        class="flex flex-1 flex-col gap-0.5 overflow-y-auto bg-zinc-100 px-3 py-4 lg:px-6 dark:bg-zinc-900"
    >
        @php $prev = null; @endphp
        @forelse ($messages as $message)
            @php
                $mine = $message->user_id === $me->id;
                $startsGroup = $prev === null
                    || $prev->user_id !== $message->user_id
                    || $message->created_at->diffInMinutes($prev->created_at) >= 5;
                $showName = ! $mine && $showSenderNames && $startsGroup;
                $images = $message->attachments->filter->isImage();
                $files = $message->attachments->reject->isImage();
                $prev = $message;
            @endphp
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

                <div @class([
                    'relative max-w-[78%] rounded-2xl px-2.5 py-1.5 shadow-sm sm:max-w-[70%]',
                    'bg-brand-500 text-white' => $mine,
                    'bg-white text-zinc-800 dark:bg-zinc-800 dark:text-zinc-100' => ! $mine,
                    'rounded-tr-md' => $mine && $startsGroup,
                    'rounded-tl-md' => ! $mine && $startsGroup,
                ])>
                    @if ($showName)
                        <div class="mb-0.5 text-xs font-semibold text-brand-600 dark:text-brand-400">{{ $message->user?->name ?? __('Onbekend') }}</div>
                    @endif

                    @if ($images->isNotEmpty())
                        <div @class([
                            'mb-1 grid gap-1',
                            'grid-cols-2' => $images->count() > 1,
                            'grid-cols-1' => $images->count() === 1,
                        ])>
                            @foreach ($images as $image)
                                <img
                                    src="{{ route('attachments.show', $image) }}"
                                    alt="{{ $image->filename }}"
                                    loading="lazy"
                                    x-on:click="lightbox = '{{ route('attachments.show', $image) }}'"
                                    @class([
                                        'w-full cursor-zoom-in rounded-lg object-cover',
                                        'max-h-72' => $images->count() === 1,
                                        'aspect-square' => $images->count() > 1,
                                    ])
                                />
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
                        ])>{!! \App\Support\Mentions::render($message->body) !!}</div>
                    @endif

                    <div @class([
                        'mt-0.5 text-right text-[10px] leading-none',
                        'text-white/70' => $mine,
                        'text-zinc-400' => ! $mine,
                    ])>{{ $message->created_at->format('H:i') }}</div>
                </div>

                @if ($canDraftTicket)
                    <flux:tooltip :content="__('Maak ticket van dit bericht')">
                        <flux:button wire:click="openTicketDraft({{ $message->id }})" size="xs" variant="subtle" icon="sparkles" inset="top bottom" class="opacity-0 transition group-hover:opacity-100" />
                    </flux:tooltip>
                @endif
            </div>
        @empty
            <div class="flex h-full flex-col items-center justify-center gap-2 text-center text-zinc-400">
                <flux:icon name="chat-bubble-left-right" class="size-10 text-zinc-300 dark:text-zinc-600" />
                {{ $slot->isNotEmpty() ? $slot : __('Start het gesprek.') }}
            </div>
        @endforelse
    </div>

    {{-- Image lightbox --}}
    <template x-if="lightbox">
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
            x-on:click="lightbox = null"
            x-transition.opacity
        >
            <button type="button" class="absolute end-4 top-4 flex size-10 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20" aria-label="{{ __('Sluiten') }}">
                <flux:icon name="x-mark" class="size-6" />
            </button>
            <img :src="lightbox" alt="" class="max-h-full max-w-full rounded-lg object-contain shadow-2xl" x-on:click.stop />
            <a :href="lightbox" download class="absolute bottom-4 end-4 flex items-center gap-1.5 rounded-full bg-white/10 px-4 py-2 text-sm text-white transition hover:bg-white/20" x-on:click.stop>
                <flux:icon name="arrow-down-tray" class="size-4" />
                {{ __('Download') }}
            </a>
        </div>
    </template>
</div>
