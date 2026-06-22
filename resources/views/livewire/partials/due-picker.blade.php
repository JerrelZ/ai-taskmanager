{{--
    Inline deadline quick-picker. Works in any Livewire component that exposes a
    `setDue(int $id, ?string $due)` action. Offers the common presets inline;
    arbitrary dates are still editable from the task detail panel.
--}}
@php
    $hasDue = $task->due_date !== null;
    $overdue = $hasDue && $task->due_date->isPast() && ! $task->isComplete();
@endphp
<flux:dropdown wire:sort:ignore position="bottom" align="end">
    <button
        type="button"
        x-on:click.stop
        aria-label="{{ __('Deadline aanpassen') }}"
        @class([
            'flex shrink-0 cursor-pointer items-center gap-1 rounded px-1 py-0.5 text-xs transition hover:bg-zinc-100 dark:hover:bg-zinc-700',
            'text-red-500' => $overdue,
            'text-zinc-400' => ! $overdue,
            'opacity-0 group-hover:opacity-100 focus-visible:opacity-100' => ! $hasDue,
        ])
    >
        <flux:icon name="calendar" variant="micro" />
        @if ($hasDue)
            <span>{{ $task->due_date->translatedFormat('j M') }}</span>
        @endif
    </button>

    <flux:menu>
        <flux:menu.item x-on:click.stop wire:click="setDue({{ $task->id }}, '{{ now()->format('Y-m-d') }}')">{{ __('Vandaag') }}</flux:menu.item>
        <flux:menu.item x-on:click.stop wire:click="setDue({{ $task->id }}, '{{ now()->addDay()->format('Y-m-d') }}')">{{ __('Morgen') }}</flux:menu.item>
        <flux:menu.item x-on:click.stop wire:click="setDue({{ $task->id }}, '{{ now()->addWeek()->startOfWeek()->format('Y-m-d') }}')">{{ __('Volgende week') }}</flux:menu.item>
        @if ($hasDue)
            <flux:menu.separator />
            <flux:menu.item x-on:click.stop wire:click="setDue({{ $task->id }}, null)" icon="x-mark" variant="danger">{{ __('Wissen') }}</flux:menu.item>
        @endif
    </flux:menu>
</flux:dropdown>
