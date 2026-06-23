<div class="mx-auto flex h-full w-full max-w-3xl flex-1 flex-col gap-6 p-4 lg:p-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl leading-none text-zinc-900 lg:text-4xl dark:text-zinc-50">{{ __('Meldingen') }}</h1>
            <flux:subheading class="mt-1.5">{{ __('Al je meldingen op één plek.') }}</flux:subheading>
        </div>
        @if ($this->unreadCount > 0)
            <flux:button wire:click="markAllAsRead" variant="subtle" size="sm" icon="check" class="shrink-0">
                {{ __('Alles gelezen') }}
            </flux:button>
        @endif
    </div>

    <div class="flex flex-col divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-700 dark:bg-zinc-900">
        @forelse ($notifications as $notification)
            <a href="{{ $notification->data['url'] ?? '#' }}" wire:navigate
                wire:key="notification-{{ $notification->id }}"
                wire:click="markAsRead('{{ $notification->id }}')"
                @class([
                    'flex items-start gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60',
                    'bg-blue-50/60 dark:bg-blue-950/30' => $notification->read_at === null,
                ])>
                <flux:icon :name="$notification->data['icon'] ?? 'bell'" class="mt-0.5 size-5 shrink-0 text-zinc-400" />
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $notification->data['title'] ?? '' }}</div>
                    @if (filled($notification->data['body'] ?? ''))
                        <div class="mt-0.5 line-clamp-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $notification->data['body'] }}</div>
                    @endif
                    <div class="mt-1 text-[11px] text-zinc-400">{{ $notification->created_at->diffForHumans() }}</div>
                </div>
                @if ($notification->read_at === null)
                    <span class="mt-1.5 size-2 shrink-0 rounded-full bg-blue-500" title="{{ __('Ongelezen') }}"></span>
                @endif
            </a>
        @empty
            <div class="flex flex-col items-center gap-2 px-4 py-16 text-center">
                <flux:icon name="bell-slash" class="size-8 text-zinc-300 dark:text-zinc-600" />
                <flux:text class="text-zinc-500">{{ __('Nog geen meldingen.') }}</flux:text>
            </div>
        @endforelse
    </div>

    @if ($notifications->hasPages())
        <div>{{ $notifications->links() }}</div>
    @endif
</div>
