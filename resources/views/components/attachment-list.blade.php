{{--
    Linear-style attachment list. Images and videos render as thumbnail tiles
    that open in the shared <x-attachment-viewer />; PDFs and other files render
    as cards that download on click. Pass `deleteMethod` to surface a hover
    delete button wired to that Livewire method (called with the attachment id).
--}}
@props([
    'attachments',
    'deleteMethod' => null,
])

@php
    $media = $attachments->filter(fn ($attachment) => $attachment->isPreviewable());
    $files = $attachments->reject(fn ($attachment) => $attachment->isPreviewable());
@endphp

@if ($attachments->isNotEmpty())
    <div class="space-y-2">
        @if ($media->isNotEmpty())
            <div class="flex flex-wrap gap-2">
                @foreach ($media as $item)
                    <div wire:key="att-media-{{ $item->id }}" class="group/att relative">
                        <button
                            type="button"
                            x-on:click="$dispatch('attachment-open', {
                                url: '{{ route('attachments.show', $item) }}',
                                download: '{{ route('attachments.download', $item) }}',
                                type: '{{ $item->isVideo() ? 'video' : 'image' }}',
                            })"
                            class="block size-24 cursor-zoom-in overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100 transition hover:opacity-90 dark:border-zinc-700 dark:bg-zinc-800"
                            title="{{ $item->filename }}"
                        >
                            @if ($item->isVideo())
                                <span class="relative block size-full">
                                    {{-- #t=0.1 nudges the browser to paint the first frame as a poster. --}}
                                    <video src="{{ route('attachments.show', $item) }}#t=0.1" preload="metadata" muted playsinline class="size-full object-cover"></video>
                                    <span class="absolute inset-0 flex items-center justify-center bg-black/20">
                                        <span class="flex size-8 items-center justify-center rounded-full bg-black/50 text-white">
                                            <flux:icon name="play" variant="solid" class="size-4" />
                                        </span>
                                    </span>
                                </span>
                            @else
                                <img src="{{ route('attachments.show', $item) }}" alt="{{ $item->filename }}" loading="lazy" class="size-full object-cover" />
                            @endif
                        </button>

                        @if ($deleteMethod)
                            <flux:button
                                wire:click="{{ $deleteMethod }}({{ $item->id }})"
                                variant="filled"
                                size="xs"
                                icon="trash"
                                class="absolute end-1 top-1 opacity-0 transition group-hover/att:opacity-100"
                                :tooltip="__('Verwijderen')"
                            />
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($files->isNotEmpty())
            <div class="space-y-1">
                @foreach ($files as $file)
                    <div wire:key="att-file-{{ $file->id }}" class="group/att flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        @if ($file->isPdf())
                            <flux:icon name="document-text" class="size-4 shrink-0 text-red-500" />
                        @else
                            <flux:icon name="paper-clip" class="size-4 shrink-0 text-zinc-400" />
                        @endif
                        <a href="{{ route('attachments.download', $file) }}" class="min-w-0 flex-1 truncate text-sm text-zinc-700 hover:underline dark:text-zinc-200">{{ $file->filename }}</a>
                        <span class="shrink-0 text-xs text-zinc-400">{{ $file->humanSize() }}</span>
                        @if ($deleteMethod)
                            <flux:button
                                wire:click="{{ $deleteMethod }}({{ $file->id }})"
                                variant="subtle"
                                size="xs"
                                icon="trash"
                                class="opacity-0 transition group-hover/att:opacity-100"
                                :tooltip="__('Verwijderen')"
                            />
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
