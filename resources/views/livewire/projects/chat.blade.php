<div class="flex h-full flex-col" wire:poll.3s>
    <x-chat.thread :messages="$this->thread" :me="auth()->user()">
        {{ __('Start het gesprek over dit project.') }}
    </x-chat.thread>

    <x-chat.composer :mentions="$this->people->pluck('name')" :pending="$newChatAttachments" :placeholder="__('Schrijf een bericht... (@naam om te taggen)')" />
</div>
