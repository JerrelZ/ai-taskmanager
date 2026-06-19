{{--
    Inline assignee picker. Works in any Livewire component that exposes a
    `setAssignee(int $id, ?int $userId)` action and a `$this->users` collection.
--}}
<flux:dropdown wire:sort:ignore position="bottom" align="end">
    <button
        type="button"
        x-on:click.stop
        aria-label="{{ __('Toewijzen') }}"
        class="flex shrink-0 cursor-pointer items-center justify-center rounded-full transition hover:opacity-80"
    >
        @if ($task->assignee)
            <flux:avatar size="xs" circle :name="$task->assignee->name" :initials="$task->assignee->initials()" />
        @else
            <span class="flex size-6 items-center justify-center rounded-full border border-dashed border-zinc-300 text-zinc-400 dark:border-zinc-600">
                <flux:icon name="user" variant="micro" />
            </span>
        @endif
    </button>

    <flux:menu class="max-h-72 overflow-y-auto">
        <flux:menu.item x-on:click.stop wire:click="setAssignee({{ $task->id }}, null)" :icon="$task->assignee_id === null ? 'check' : null">
            {{ __('Niemand') }}
        </flux:menu.item>
        @foreach ($this->users as $user)
            <flux:menu.item x-on:click.stop wire:click="setAssignee({{ $task->id }}, {{ $user->id }})" :icon="$task->assignee_id === $user->id ? 'check' : null">
                <span class="flex items-center gap-2">
                    <flux:avatar size="xs" circle :name="$user->name" :initials="$user->initials()" />
                    {{ $user->name }}
                </span>
            </flux:menu.item>
        @endforeach
    </flux:menu>
</flux:dropdown>
