@props([
    'mentions' => [],
    'placeholder' => null,
    'attachments' => true,
    'pending' => [],
    'draftKey' => null,
    'replyTo' => null,
    'conversationId' => null,
    'userName' => null,
])

<div
    @if ($draftKey) wire:key="composer-{{ $draftKey }}" @endif
    class="border-t border-zinc-200 bg-zinc-100 px-3 py-3 lg:px-4 dark:border-zinc-700 dark:bg-zinc-900"
    x-data="chatComposer(@js(collect($mentions)->values()), @js($draftKey), @js($conversationId), @js($userName))"
    x-on:message-sent.window="reset()"
    x-on:reply-started.window="$refs.input?.focus()"
>
    @if ($replyTo)
        {{-- Reply context: the message the next send will answer. --}}
        <div class="mb-2 flex items-center gap-2 rounded-lg border-s-2 border-brand-400 bg-white px-3 py-1.5 dark:bg-zinc-800">
            <div class="min-w-0 flex-1">
                <div class="text-xs font-medium text-brand-600 dark:text-brand-400">{{ __('Antwoord aan :name', ['name' => $replyTo->user?->name ?? __('Onbekend')]) }}</div>
                <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit($replyTo->body, 80) ?: __('Bijlage') }}</div>
            </div>
            <flux:button wire:click="cancelReply" size="xs" variant="subtle" icon="x-mark" :tooltip="__('Annuleer antwoord')" />
        </div>
    @endif
    @if ($attachments)
        {{-- Selected attachments preview tray --}}
        @if (count($pending) > 0)
            <div class="mb-2 flex flex-wrap gap-2">
                @foreach ($pending as $i => $file)
                    <div wire:key="new-att-{{ $i }}" class="group relative">
                        @if (str_starts_with((string) $file->getMimeType(), 'image/'))
                            <img src="{{ $file->temporaryUrl() }}" alt="" class="size-16 rounded-lg object-cover" />
                        @elseif (str_starts_with((string) $file->getMimeType(), 'video/'))
                            <div class="relative size-16 overflow-hidden rounded-lg bg-zinc-900">
                                <video src="{{ $file->temporaryUrl() }}#t=0.1" preload="metadata" muted playsinline class="size-full object-cover"></video>
                                <span class="absolute inset-0 flex items-center justify-center">
                                    <flux:icon name="play" variant="solid" class="size-5 text-white/90" />
                                </span>
                            </div>
                        @else
                            <div class="flex size-16 flex-col items-center justify-center gap-1 rounded-lg border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-800">
                                <flux:icon name="document" class="size-5 text-zinc-400" />
                                <span class="w-full truncate px-0.5 text-center text-[9px] text-zinc-500">{{ $file->getClientOriginalName() }}</span>
                            </div>
                        @endif
                        <button
                            type="button"
                            wire:click="removeNewAttachment({{ $i }})"
                            class="absolute -end-1.5 -top-1.5 flex size-5 items-center justify-center rounded-full bg-zinc-700 text-white shadow ring-2 ring-zinc-100 transition hover:bg-zinc-900 dark:ring-zinc-900"
                            aria-label="{{ __('Verwijder bijlage') }}"
                        >
                            <flux:icon name="x-mark" class="size-3" />
                        </button>
                    </div>
                @endforeach
            </div>
        @endif

        <div wire:loading.flex wire:target="newChatAttachments" class="mb-2 items-center gap-1.5 text-xs text-zinc-400">
            <flux:icon name="arrow-path" class="size-3.5 animate-spin" />
            {{ __('Bijlage uploaden...') }}
        </div>
    @endif

    <form wire:submit="send" x-ref="form" class="flex items-end gap-2">
        <div class="relative flex flex-1 items-end gap-0.5 rounded-3xl border border-zinc-200 bg-white px-1.5 py-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            {{-- Emoji picker --}}
            <div class="relative shrink-0" x-on:click.outside="emojiOpen = false">
                <button
                    type="button"
                    x-on:click="toggleEmoji()"
                    :class="emojiOpen ? 'text-brand-500' : 'text-zinc-500'"
                    class="flex size-9 items-center justify-center rounded-full transition hover:bg-zinc-100 dark:hover:bg-zinc-700"
                    aria-label="{{ __('Emoji') }}"
                >
                    <flux:icon name="face-smile" class="size-5" />
                </button>

                <div
                    x-show="emojiOpen"
                    x-cloak
                    x-transition.origin.bottom.left
                    class="absolute bottom-full start-0 z-30 mb-2 w-72 max-w-[80vw] overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
                >
                    <div class="flex gap-0.5 overflow-x-auto border-b border-zinc-100 px-2 py-1.5 dark:border-zinc-700">
                        <template x-for="(cat, i) in emojiCategories" :key="cat.label">
                            <button
                                type="button"
                                x-on:click="activeCategory = i"
                                :class="activeCategory === i ? 'bg-brand-50 dark:bg-brand-950/40' : 'opacity-60 hover:opacity-100'"
                                class="flex size-7 shrink-0 items-center justify-center rounded-lg text-base"
                                :title="cat.label"
                                x-text="cat.icon"
                            ></button>
                        </template>
                    </div>
                    <div class="grid max-h-48 grid-cols-8 gap-0.5 overflow-y-auto p-2">
                        <template x-for="emoji in emojiCategories[activeCategory].emojis" :key="emoji">
                            <button
                                type="button"
                                x-on:click="insertEmoji(emoji)"
                                class="flex size-7 items-center justify-center rounded-lg text-lg transition hover:bg-zinc-100 dark:hover:bg-zinc-700"
                                x-text="emoji"
                            ></button>
                        </template>
                    </div>
                </div>
            </div>

            @if ($attachments)
                {{-- Attachment upload --}}
                <label class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
                    title="{{ __('Bijlage toevoegen') }}">
                    <flux:icon name="paper-clip" class="size-5" />
                    <input type="file" wire:model="newChatAttachments" multiple class="hidden" />
                </label>
            @endif

            <textarea
                x-ref="input"
                wire:model="body"
                x-on:input="onInput()"
                x-on:keydown="onKeydown($event)"
                x-on:paste="onPaste($event)"
                rows="1"
                placeholder="{{ $placeholder ?? __('Schrijf een bericht...') }}"
                class="max-h-40 flex-1 resize-none border-0 bg-transparent px-1 py-2 text-base text-zinc-800 placeholder-zinc-400 focus:outline-none focus:ring-0 dark:text-zinc-100"
            ></textarea>

            {{-- Mention autocomplete --}}
            <div x-show="open" x-cloak class="absolute bottom-full start-0 z-20 mb-2 max-h-48 w-64 overflow-y-auto rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                <template x-for="(name, i) in matches" :key="name">
                    <button type="button" x-on:mousedown.prevent="choose(name)" :class="i === active ? 'bg-brand-50 dark:bg-brand-950/40' : ''" class="block w-full px-3 py-1.5 text-start text-sm text-zinc-700 dark:text-zinc-200">@<span x-text="name"></span></button>
                </template>
            </div>

            {{-- Slash-command autocomplete --}}
            <div x-show="cmdOpen" x-cloak class="absolute bottom-full start-0 z-20 mb-2 w-72 overflow-hidden rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                <template x-for="(command, i) in cmdMatches" :key="command.name">
                    <button type="button" x-on:mousedown.prevent="chooseCommand(command.name)" :class="i === cmdActive ? 'bg-brand-50 dark:bg-brand-950/40' : ''" class="flex w-full flex-col px-3 py-1.5 text-start">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">/<span x-text="command.name"></span></span>
                        <span class="text-xs text-zinc-400" x-text="command.hint"></span>
                    </button>
                </template>
            </div>
        </div>

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="send"
            class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-500 text-white shadow-sm transition hover:bg-brand-600 active:scale-95 disabled:opacity-50"
            aria-label="{{ __('Verstuur') }}"
        >
            <flux:icon name="paper-airplane" class="size-5" />
        </button>
    </form>
</div>
