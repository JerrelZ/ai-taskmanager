<div class="flex h-full flex-col" wire:poll.3s="pollChat">
    <x-chat.thread :messages="$this->thread" :me="auth()->user()">
        {{ __('Start het gesprek over dit project.') }}
    </x-chat.thread>

    <x-chat.composer :mentions="$this->people->pluck('name')" :pending="$newChatAttachments" />
</div>
