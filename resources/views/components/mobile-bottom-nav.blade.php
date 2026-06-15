@php
    $unreadMessages = auth()->user()->unreadMessagesCount();

    $items = [
        [
            'label' => __('Tickets'),
            'icon' => 'queue-list',
            'href' => route('tickets.index'),
            'current' => request()->routeIs('tickets.index') && ! request()->boolean('onlyStale'),
            'badge' => null,
        ],
        [
            'label' => __('Berichten'),
            'icon' => 'chat-bubble-left-right',
            'href' => route('messages.index'),
            'current' => request()->routeIs('messages.*'),
            'badge' => $unreadMessages > 0 ? $unreadMessages : null,
        ],
        [
            'label' => __('Projecten'),
            'icon' => 'rectangle-stack',
            'href' => route('projects.index'),
            'current' => request()->routeIs('projects.*'),
            'badge' => null,
        ],
    ];
@endphp

<nav
    x-data
    x-show="! $store.mobileNav.hiddenForChat"
    class="fixed inset-x-0 bottom-0 z-30 flex border-t border-zinc-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden dark:border-zinc-700 dark:bg-zinc-900/95"
    aria-label="{{ __('Hoofdnavigatie') }}"
>
    @foreach ($items as $item)
        <a
            href="{{ $item['href'] }}"
            wire:navigate
            @class([
                'relative flex flex-1 flex-col items-center justify-center gap-1 py-2.5 text-xs font-medium transition',
                'text-brand-600 dark:text-brand-400' => $item['current'],
                'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100' => ! $item['current'],
            ])
            @if ($item['current']) aria-current="page" @endif
        >
            <span class="relative">
                <flux:icon :name="$item['icon']" class="size-6" />
                @if ($item['badge'])
                    <span class="absolute -end-2 -top-1.5 flex min-w-4 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-semibold leading-4 text-white">
                        {{ $item['badge'] > 99 ? '99+' : $item['badge'] }}
                    </span>
                @endif
            </span>
            <span>{{ $item['label'] }}</span>
        </a>
    @endforeach
</nav>
