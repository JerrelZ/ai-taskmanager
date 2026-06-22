{{-- Marks a ticket imported from Linear as a small "L" dot; the original
     identifier and a hint to check Linear for comments live in the tooltip. --}}
@if ($task->linear_id)
    <flux:tooltip :content="__('Open in Linear').' · '.$task->linear_id.' · '.__('bekijk daar voor reacties')">
        <a href="{{ $task->linearUrl() }}" target="_blank" rel="noopener noreferrer" x-on:click.stop
            aria-label="{{ __('Open in Linear') . ': ' . $task->linear_id }}"
            class="inline-flex size-3 shrink-0 items-center justify-center rounded-full bg-indigo-500 text-[0.5rem] font-bold leading-none text-white transition hover:bg-indigo-600 dark:bg-indigo-400 dark:text-indigo-950 dark:hover:bg-indigo-300">L</a>
    </flux:tooltip>
@endif
