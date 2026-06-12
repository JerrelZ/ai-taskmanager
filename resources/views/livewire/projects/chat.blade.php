<div class="flex h-full flex-col">
    {{-- Messages --}}
    <div
        wire:poll.5s="$refresh"
        x-data
        x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
        @message-sent.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
        class="mx-auto w-full max-w-3xl flex-1 space-y-5 overflow-y-auto px-6 py-6"
    >
        @forelse ($this->messages as $message)
            <div wire:key="message-{{ $message->id }}" class="flex gap-3">
                <flux:avatar size="sm" circle :name="$message->user?->name ?? '?'" :initials="$message->user?->initials() ?? '?'" />
                <div class="flex-1">
                    <div class="flex items-baseline gap-2">
                        <flux:heading size="sm">{{ $message->user?->name ?? __('Onbekend') }}</flux:heading>
                        <flux:text size="sm" class="text-zinc-400">{{ $message->created_at->diffForHumans() }}</flux:text>
                    </div>
                    <div class="mt-0.5 text-sm text-zinc-700 dark:text-zinc-200">{!! \App\Support\Mentions::render($message->body) !!}</div>
                </div>
            </div>
        @empty
            <div class="flex h-full flex-col items-center justify-center gap-2 text-center">
                <flux:icon name="chat-bubble-left-right" class="size-10 text-zinc-300 dark:text-zinc-600" />
                <flux:heading>{{ __('Nog geen berichten') }}</flux:heading>
                <flux:subheading>{{ __('Start het gesprek over dit project.') }}</flux:subheading>
            </div>
        @endforelse
    </div>

    {{-- Composer --}}
    <div class="border-t border-zinc-200 dark:border-zinc-700">
        <form wire:submit="send" x-on:submit="$dispatch('message-sent')" class="mx-auto flex w-full max-w-3xl items-end gap-2 px-6 py-4">
            <flux:textarea wire:model="body" rows="1" class="flex-1" placeholder="{{ __('Schrijf een bericht...') }}" />
            <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Verstuur') }}</flux:button>
        </form>
    </div>
</div>
