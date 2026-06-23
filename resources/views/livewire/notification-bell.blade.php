<div wire:poll.15s>
    <flux:dropdown position="bottom" align="end">
        <flux:button variant="subtle" size="sm" icon="bell" class="relative">
            @if ($this->unreadCount > 0)
                <flux:badge size="sm" color="red" class="absolute -right-1 -top-1 px-1">{{ $this->unreadCount }}</flux:badge>
            @endif
        </flux:button>

        <flux:menu class="w-80">
            <div class="flex items-center justify-between px-3 py-2">
                <flux:heading size="sm">{{ __('Meldingen') }}</flux:heading>
                @if ($this->unreadCount > 0)
                    <flux:button wire:click="markAllAsRead" variant="ghost" size="xs">{{ __('Alles gelezen') }}</flux:button>
                @endif
            </div>
            <flux:menu.separator />

            @forelse ($this->notifications as $notification)
                <a href="{{ $notification->data['url'] ?? '#' }}" wire:navigate
                    wire:click="markAsRead('{{ $notification->id }}')"
                    @class([
                        'flex items-start gap-3 px-3 py-2 transition hover:bg-zinc-50 dark:hover:bg-zinc-800',
                        'bg-blue-50/60 dark:bg-blue-950/30' => $notification->read_at === null,
                    ])>
                    <flux:icon :name="$notification->data['icon'] ?? 'bell'" class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $notification->data['title'] ?? '' }}</div>
                        <div class="line-clamp-2 text-xs text-zinc-500">{{ $notification->data['body'] ?? '' }}</div>
                        <div class="mt-0.5 text-[11px] text-zinc-400">{{ $notification->created_at->diffForHumans() }}</div>
                    </div>
                </a>
            @empty
                <div class="px-3 py-6 text-center text-sm text-zinc-500">{{ __('Geen meldingen.') }}</div>
            @endforelse

            <flux:menu.separator />
            <flux:menu.item :href="route('notifications.index')" wire:navigate icon="inbox-stack">
                {{ __('Alle meldingen') }}
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>
