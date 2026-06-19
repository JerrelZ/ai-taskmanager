{{--
    Full-screen media viewer for image and video attachments. Drop a single
    instance on a page; any element can open it by dispatching an
    `attachment-open` window event with detail { url, type, download, filename }.
--}}
<div
    x-data="{ open: false, url: null, type: null, download: null }"
    x-on:attachment-open.window="open = true; url = $event.detail.url; type = $event.detail.type; download = $event.detail.download"
    x-on:keydown.escape.window="open = false; url = null"
>
    <template x-if="open">
        <div
            class="fixed inset-0 z-[60] flex items-center justify-center bg-black/80 p-4 backdrop-blur-sm"
            x-on:click="open = false; url = null"
            x-transition.opacity
        >
            <button
                type="button"
                class="absolute end-4 top-4 flex size-10 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                aria-label="{{ __('Sluiten') }}"
            >
                <flux:icon name="x-mark" class="size-6" />
            </button>

            <template x-if="type === 'image'">
                <img :src="url" alt="" class="max-h-full max-w-full rounded-lg object-contain shadow-2xl" x-on:click.stop />
            </template>

            <template x-if="type === 'video'">
                {{-- eslint-disable-next-line --}}
                <video :src="url" controls autoplay playsinline class="max-h-full max-w-full rounded-lg shadow-2xl" x-on:click.stop></video>
            </template>

            <a
                :href="download"
                class="absolute bottom-4 end-4 flex items-center gap-1.5 rounded-full bg-white/10 px-4 py-2 text-sm text-white transition hover:bg-white/20"
                x-on:click.stop
            >
                <flux:icon name="arrow-down-tray" class="size-4" />
                {{ __('Download') }}
            </a>
        </div>
    </template>
</div>
